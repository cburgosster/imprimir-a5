<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require_once __DIR__.'/../extras/fs_pdf.php';
require_model('articulo_traza.php');
require_model('cliente.php');
require_model('cuenta_banco.php');
require_model('cuenta_banco_cliente.php');
require_model('forma_pago.php');
require_model('pais.php');

/**
 * Description of imprimirA5
 *
 * @author carlos
 */
class imprimirA5 extends fs_controller {

    public $articulo_traza;
    public $cliente;
    public $documento;
    public $impresion;
    public $impuesto;
    private $numpaginas;

    public function __construct($name = __CLASS__, $title = 'Imprimir A5', $folder = 'ventas', $admin = FALSE, $shmenu = FALSE, $important = FALSE) {
        parent::__construct($name, $title, $folder, $admin, $shmenu, $important);
    }

    protected function private_core() {
        $this->share_extension();
        $this->template = FALSE;

        $this->cliente = FALSE;
        $this->documento = FALSE;
        $this->impuesto = new impuesto();

        /// obtenemos los datos de configuración de impresión
        $this->impresion = array(
            'print_ref' => '1',
            'print_dto' => '1',
            'print_alb' => '0',
            'print_formapago' => '1'
        );
        $fsvar = new fs_var();
        $this->impresion = $fsvar->array_get($this->impresion, FALSE);

        if (isset($_REQUEST['albaran']) AND isset($_REQUEST['id'])) {
            $this->articulo_traza = new articulo_traza();

            $alb = new albaran_cliente();
            $this->documento = $alb->get($_REQUEST['id']);
            if ($this->documento) {
                $cliente = new cliente();
                $this->cliente = $cliente->get($this->documento->codcliente);
            }

            $this->generar_pdf_albaran();
        } else if (isset($_REQUEST['factura']) AND isset($_REQUEST['id'])) {
            $this->articulo_traza = new articulo_traza();

            $fac = new factura_cliente();
            $this->documento = $fac->get($_REQUEST['id']);
            if ($this->documento) {
                $cliente = new cliente();
                $this->cliente = $cliente->get($this->documento->codcliente);
            }

            $this->generar_pdf_factura();
        }
    }

    private function share_extension() {
        $fsext = new fs_extension();
        $fsext->name = 'imprimir_albaran_a5';
        $fsext->from = __CLASS__;
        $fsext->to = 'ventas_albaran';
        $fsext->type = 'pdf';
        $fsext->text = '<span class="glyphicon glyphicon-print"></span>&nbsp;A5';
        $fsext->params = '&albaran=TRUE';
        $fsext->save();

        $fsext = new fs_extension();
        $fsext->name = 'imprimir_factura_a5';
        $fsext->from = __CLASS__;
        $fsext->to = 'ventas_factura';
        $fsext->type = 'pdf';
        $fsext->text = '<span class="glyphicon glyphicon-print"></span>&nbsp;A5';
        $fsext->params = '&factura=TRUE';
        $fsext->save();
    }

    private function generar_pdf_lineas(&$pdf_doc, &$lineas, &$linea_actual, &$lppag) {
        /// calculamos el número de páginas
        if (!isset($this->numpaginas)) {
            $this->numpaginas = 0;
            $linea_a = 0;
            while ($linea_a < count($lineas)) {
                $lppag2 = $lppag;
                foreach ($lineas as $i => $lin) {
                    if ($i >= $linea_a AND $i < $linea_a + $lppag2) {
                        $linea_size = 1;
                        $len = mb_strlen($lin->referencia . ' ' . $lin->descripcion);
                        while ($len > 85) {
                            $len -= 85;
                            $linea_size += 0.5;
                        }

                        $aux = explode("\n", $lin->descripcion);
                        if (count($aux) > 1) {
                            $linea_size += 0.5 * ( count($aux) - 1);
                        }

                        if ($linea_size > 1) {
                            $lppag2 -= $linea_size - 1;
                        }
                    }
                }

                $linea_a += $lppag2;
                $this->numpaginas++;
            }

            if ($this->numpaginas == 0) {
                $this->numpaginas = 1;
            }
        }

        if ($this->impresion['print_dto']) {
            $this->impresion['print_dto'] = FALSE;

            /// leemos las líneas para ver si de verdad mostramos los descuentos
            foreach ($lineas as $lin) {
                if ($lin->dtopor != 0) {
                    $this->impresion['print_dto'] = TRUE;
                    break;
                }
            }
        }

        $dec_cantidad = 0;
        $multi_iva = FALSE;
        $multi_re = FALSE;
        $multi_irpf = FALSE;
        $iva = FALSE;
        $re = FALSE;
        $irpf = FALSE;
        /// leemos las líneas para ver si hay que mostrar los tipos de iva, re o irpf
        foreach ($lineas as $i => $lin) {
            if ($lin->cantidad != intval($lin->cantidad)) {
                $dec_cantidad = 2;
            }

            if ($iva === FALSE) {
                $iva = $lin->iva;
            } else if ($lin->iva != $iva) {
                $multi_iva = TRUE;
            }

            if ($re === FALSE) {
                $re = $lin->recargo;
            } else if ($lin->recargo != $re) {
                $multi_re = TRUE;
            }

            if ($irpf === FALSE) {
                $irpf = $lin->irpf;
            } else if ($lin->irpf != $irpf) {
                $multi_irpf = TRUE;
            }

            /// restamos líneas al documento en función del tamaño de la descripción
            if ($i >= $linea_actual AND $i < $linea_actual + $lppag) {
                $linea_size = 1;
                $len = mb_strlen($lin->referencia . ' ' . $lin->descripcion);
                while ($len > 85) {
                    $len -= 85;
                    $linea_size += 0.5;
                }

                $aux = explode("\n", $lin->descripcion);
                if (count($aux) > 1) {
                    $linea_size += 0.5 * ( count($aux) - 1);
                }

                if ($linea_size > 1) {
                    $lppag -= $linea_size - 1;
                }
            }
        }

        /*
         * Creamos la tabla con las lineas del documento
         */
        $pdf_doc->new_table();
        $table_header = array(
            'alb' => '<b>' . ucfirst(FS_ALBARAN) . '</b>',
            'descripcion' => '<b>Ref. + Descripción</b>',
            'cantidad' => '<b>Cant.</b>',
            'pvp' => '<b>Precio</b>',
        );

        /// ¿Desactivamos la columna de albaran?
        if (get_class_name($this->documento) == 'factura_cliente') {
            if ($this->impresion['print_alb']) {
                /// aunque esté activada, si la factura no viene de un albaran, la desactivamos
                $this->impresion['print_alb'] = FALSE;
                foreach ($lineas as $lin) {
                    if ($lin->idalbaran) {
                        $this->impresion['print_alb'] = TRUE;
                        break;
                    }
                }
            }

            if (!$this->impresion['print_alb']) {
                unset($table_header['alb']);
            }
        } else {
            unset($table_header['alb']);
        }

        if ($this->impresion['print_dto'] AND ! isset($_GET['noval'])) {
            $table_header['dto'] = '<b>Dto.</b>';
        }

        if ($multi_iva AND ! isset($_GET['noval'])) {
            $table_header['iva'] = '<b>' . FS_IVA . '</b>';
        }

        if ($multi_re AND ! isset($_GET['noval'])) {
            $table_header['re'] = '<b>R.E.</b>';
        }

        if ($multi_irpf AND ! isset($_GET['noval'])) {
            $table_header['irpf'] = '<b>' . FS_IRPF . '</b>';
        }

        if (isset($_GET['noval'])) {
            unset($table_header['pvp']);
        } else {
            $table_header['importe'] = '<b>Importe</b>';
        }

        $pdf_doc->add_table_header($table_header);

        for ($i = $linea_actual; (($linea_actual < ($lppag + $i)) AND ( $linea_actual < count($lineas)));) {
            $descripcion = fs_fix_html($lineas[$linea_actual]->descripcion);
            if (!is_null($lineas[$linea_actual]->referencia)) {
                $descripcion = '<b>' . $lineas[$linea_actual]->referencia . '</b> ' . $descripcion;
            }

            /// ¿El articulo tiene trazabilidad?
            $descripcion .= $this->generar_trazabilidad($lineas[$linea_actual]);

            $fila = array(
                'alb' => '-',
                'cantidad' => $this->show_numero($lineas[$linea_actual]->cantidad, $dec_cantidad),
                'descripcion' => $descripcion,
                'pvp' => $this->show_precio($lineas[$linea_actual]->pvpunitario, $this->documento->coddivisa, TRUE, FS_NF0_ART),
                'dto' => $this->show_numero($lineas[$linea_actual]->dtopor) . " %",
                'iva' => $this->show_numero($lineas[$linea_actual]->iva) . " %",
                're' => $this->show_numero($lineas[$linea_actual]->recargo) . " %",
                'irpf' => $this->show_numero($lineas[$linea_actual]->irpf) . " %",
                'importe' => $this->show_precio($lineas[$linea_actual]->pvptotal, $this->documento->coddivisa)
            );

            if ($lineas[$linea_actual]->dtopor == 0) {
                $fila['dto'] = '';
            }

            if ($lineas[$linea_actual]->recargo == 0) {
                $fila['re'] = '';
            }

            if ($lineas[$linea_actual]->irpf == 0) {
                $fila['irpf'] = '';
            }

            if (!$lineas[$linea_actual]->mostrar_cantidad) {
                $fila['cantidad'] = '';
            }

            if (!$lineas[$linea_actual]->mostrar_precio) {
                $fila['pvp'] = '';
                $fila['dto'] = '';
                $fila['iva'] = '';
                $fila['re'] = '';
                $fila['irpf'] = '';
                $fila['importe'] = '';
            }

            if (get_class_name($lineas[$linea_actual]) == 'linea_factura_cliente' AND $this->impresion['print_alb']) {
                $fila['alb'] = $lineas[$linea_actual]->albaran_numero();
            }

            $pdf_doc->add_table_row($fila);
            $linea_actual++;
        }

        $pdf_doc->save_table(
                array(
                    'fontSize' => 8,
                    'cols' => array(
                        'cantidad' => array('justification' => 'right'),
                        'pvp' => array('justification' => 'right'),
                        'dto' => array('justification' => 'right'),
                        'iva' => array('justification' => 'right'),
                        're' => array('justification' => 'right'),
                        'irpf' => array('justification' => 'right'),
                        'importe' => array('justification' => 'right')
                    ),
                    'width' => 360,
                    'shaded' => 1,
                    'shadeCol' => array(0.95, 0.95, 0.95),
                    'lineCol' => array(0.3, 0.3, 0.3),
                )
        );

        /// ¿Última página?
        if ($linea_actual == count($lineas)) {
            if ($this->documento->observaciones != '') {
                $pdf_doc->pdf->ezText("\n" . fs_fix_html($this->documento->observaciones), 9);
            }
        }
    }

    /**
     * Devuelve el texto con los números de serie o lotes de la $linea
     * @param linea_albaran_compra $linea
     * @return string
     */
    private function generar_trazabilidad($linea) {
        $lineast = array();
        if (get_class_name($linea) == 'linea_albaran_cliente') {
            $lineast = $this->articulo_traza->all_from_linea('idlalbventa', $linea->idlinea);
        } else if (get_class_name($linea) == 'linea_factura_cliente') {
            $lineast = $this->articulo_traza->all_from_linea('idlfacventa', $linea->idlinea);
        }

        $lote = FALSE;
        $txt = '';
        foreach ($lineast as $lt) {
            $salto = "\n";
            if ($lt->numserie) {
                $txt .= $salto . 'N/S: ' . $lt->numserie . ' ';
                $salto = '';
            }

            if ($lt->lote AND $lt->lote != $lote) {
                $txt .= $salto . 'Lote: ' . $lt->lote;
                $lote = $lt->lote;
            }
        }

        return $txt;
    }

    private function generar_pdf_datos_cliente(&$pdf_doc, &$lppag) {
        $tipo_doc = ucfirst(FS_ALBARAN);
        $width_campo1 = 90;
        $rectificativa = FALSE;
        if (get_class_name($this->documento) == 'factura_cliente') {
            if ($this->documento->idfacturarect) {
                $tipo_doc = ucfirst(FS_FACTURA_RECTIFICATIVA);
                $rectificativa = TRUE;
                $width_campo1 = 110;
            } else {
                $tipo_doc = 'Factura';
            }
        }

        $tipoidfiscal = FS_CIFNIF;
        if ($this->cliente) {
            $tipoidfiscal = $this->cliente->tipoidfiscal;
        }

        /*
         * Esta es la tabla con los datos del cliente:
         * Albarán:                 Fecha:
         * Cliente:               CIF/NIF:
         * Dirección:           Teléfonos:
         */
        $pdf_doc->new_table();
        $pdf_doc->add_table_row(
                array(
                    'campo1' => "<b>" . $tipo_doc . ":</b>",
                    'dato1' => $this->documento->codigo,
                    'campo2' => "<b>Fecha:</b> " . $this->documento->fecha
                )
        );

        if ($rectificativa) {
            $pdf_doc->add_table_row(
                    array(
                        'campo1' => "<b>Original:</b>",
                        'dato1' => $this->documento->codigorect,
                        'campo2' => '',
                    )
            );
        }

        $pdf_doc->add_table_row(
                array(
                    'campo1' => "<b>Cliente:</b> ",
                    'dato1' => fs_fix_html($this->documento->nombrecliente),
                    'campo2' => "<b>" . $tipoidfiscal . ":</b> " . $this->documento->cifnif
                )
        );

        $direccion = $this->documento->direccion;
        if ($this->documento->apartado) {
            $direccion .= ' - ' . ucfirst(FS_APARTADO) . ': ' . $this->documento->apartado;
        }
        if ($this->documento->codpostal) {
            $direccion .= ' - CP: ' . $this->documento->codpostal;
        }
        $direccion .= ' - ' . $this->documento->ciudad;
        if ($this->documento->provincia) {
            $direccion .= ' (' . $this->documento->provincia . ')';
        }
        if ($this->documento->codpais != $this->empresa->codpais) {
            $pais0 = new pais();
            $pais = $pais0->get($this->documento->codpais);
            if ($pais) {
                $direccion .= ' ' . $pais->nombre;
            }
        }
        $row = array(
            'campo1' => "<b>Dirección:</b>",
            'dato1' => fs_fix_html($direccion),
            'campo2' => ''
        );

        if (!$this->cliente) {
            /// nada
        } else if ($this->cliente->telefono1) {
            $row['campo2'] = "<b>Teléfonos:</b> " . $this->cliente->telefono1;
            if ($this->cliente->telefono2) {
                $row['campo2'] .= "\n" . $this->cliente->telefono2;
                $lppag -= 2;
            }
        } else if ($this->cliente->telefono2) {
            $row['campo2'] = "<b>Teléfonos:</b> " . $this->cliente->telefono2;
        }
        $pdf_doc->add_table_row($row);

        /* Si tenemos dirección de envío y es diferente a la de facturación */
        if ($this->documento->envio_direccion && $this->documento->direccion != $this->documento->envio_direccion) {
            $direccionenv = '';
            if ($this->documento->envio_codigo) {
                $direccionenv .= 'Cod. Seg.: "' . $this->documento->envio_codigo . '" - ';
            }
            if ($this->documento->envio_nombre) {
                $direccionenv .= $this->documento->envio_nombre . ' ' . $this->documento->envio_apellidos . ' - ';
            }
            $direccionenv .= $this->documento->envio_direccion;
            if ($this->documento->envio_apartado) {
                $direccionenv .= ' - ' . ucfirst(FS_APARTADO) . ': ' . $this->documento->envio_apartado;
            }
            if ($this->documento->envio_codpostal) {
                $direccionenv .= ' - CP: ' . $this->documento->envio_codpostal;
            }
            $direccionenv .= ' - ' . $this->documento->envio_ciudad;
            if ($this->documento->envio_provincia) {
                $direccionenv .= ' (' . $this->documento->envio_provincia . ')';
            }
            if ($this->documento->envio_codpais != $this->empresa->codpais) {
                $pais0 = new pais();
                $pais = $pais0->get($this->documento->envio_codpais);
                if ($pais) {
                    $direccionenv .= ' ' . $pais->nombre;
                }
            }
            /* Tal y como está la plantilla actualmente:
             * Cada 54 caracteres es una línea en la dirección y no sabemos cuantas líneas tendrá,
             * a partir de ahí es una linea a restar por cada 54 caracteres
             */
            $lppag -= ceil(strlen($direccionenv) / 54);
            $row_dir_env = array(
                'campo1' => "<b>Enviar a:</b>",
                'dato1' => fs_fix_html($direccionenv),
                'campo2' => ''
            );
            $pdf_doc->add_table_row($row_dir_env);
        }

        if ($this->empresa->codpais != 'ESP') {
            $pdf_doc->add_table_row(
                    array(
                        'campo1' => "<b>Régimen " . FS_IVA . ":</b> ",
                        'dato1' => $this->cliente->regimeniva,
                        'campo2' => ''
                    )
            );
        }

        $pdf_doc->save_table(
                array(
                    'cols' => array(
                        'campo1' => array('width' => $width_campo1, 'justification' => 'right'),
                        'dato1' => array('justification' => 'left'),
                        'campo2' => array('justification' => 'right')
                    ),
                    'showLines' => 0,
                    'width' => 360,
                    'shaded' => 0
                )
        );
        $pdf_doc->pdf->ezText("\n", 10);
    }

    private function generar_pdf_totales(&$pdf_doc, &$lineas_iva, $pagina) {
        if (isset($_GET['noval'])) {
            $pdf_doc->pdf->addText(10, 10, 8, $pdf_doc->center_text('Página ' . $pagina . '/' . $this->numpaginas, 250));
        } else {
            /*
             * Rellenamos la última tabla de la página:
             * 
             * Página            Neto    IVA   Total
             */
            $pdf_doc->new_table();
            $titulo = array('pagina' => '<b>Página</b>', 'neto' => '<b>Neto</b>',);
            $fila = array(
                'pagina' => $pagina . '/' . $this->numpaginas,
                'neto' => $this->show_precio($this->documento->neto, $this->documento->coddivisa),
            );
            $opciones = array(
                'cols' => array(
                    'neto' => array('justification' => 'right'),
                ),
                'showLines' => 3,
                'shaded' => 2,
                'shadeCol2' => array(0.95, 0.95, 0.95),
                'lineCol' => array(0.3, 0.3, 0.3),
                'width' => 360
            );
            foreach ($lineas_iva as $li) {
                $imp = $this->impuesto->get($li['codimpuesto']);
                if ($imp) {
                    $titulo['iva' . $li['iva']] = '<b>' . $imp->descripcion . '</b>';
                } else
                    $titulo['iva' . $li['iva']] = '<b>' . FS_IVA . ' ' . $li['iva'] . '%</b>';

                $fila['iva' . $li['iva']] = $this->show_precio($li['totaliva'], $this->documento->coddivisa);

                if ($li['totalrecargo'] != 0) {
                    $fila['iva' . $li['iva']] .= "\nR.E. " . $li['recargo'] . "%: " . $this->show_precio($li['totalrecargo'], $this->documento->coddivisa);
                }

                $opciones['cols']['iva' . $li['iva']] = array('justification' => 'right');
            }

            if ($this->documento->totalirpf != 0) {
                $titulo['irpf'] = '<b>' . FS_IRPF . ' ' . $this->documento->irpf . '%</b>';
                $fila['irpf'] = $this->show_precio($this->documento->totalirpf);
                $opciones['cols']['irpf'] = array('justification' => 'right');
            }

            $titulo['liquido'] = '<b>Total</b>';
            $fila['liquido'] = $this->show_precio($this->documento->total, $this->documento->coddivisa);
            $opciones['cols']['liquido'] = array('justification' => 'right');

            $pdf_doc->add_table_header($titulo);
            $pdf_doc->add_table_row($fila);
            $pdf_doc->save_table($opciones);
        }
    }

    public function generar_pdf_albaran() {
        /// Creamos el PDF y escribimos sus metadatos
        $pdf_doc = new fs_pdf('a5');
        $pdf_doc->pdf->addInfo('Title', ucfirst(FS_ALBARAN) . ' ' . $this->documento->codigo);
        $pdf_doc->pdf->addInfo('Subject', ucfirst(FS_ALBARAN) . ' de cliente ' . $this->documento->codigo);
        $pdf_doc->pdf->addInfo('Author', $this->empresa->nombre);

        $lineas = $this->documento->get_lineas();
        $lineas_iva = $pdf_doc->get_lineas_iva($lineas);
        if ($lineas) {
            $linea_actual = 0;
            $pagina = 1;

            /// imprimimos las páginas necesarias
            while ($linea_actual < count($lineas)) {
                $lppag = 15;

                /// salto de página
                if ($linea_actual > 0) {
                    $pdf_doc->pdf->ezNewPage();
                }

                $pdf_doc->generar_pdf_cabecera($this->empresa, $lppag);
                $this->generar_pdf_datos_cliente($pdf_doc, $lppag);
                $this->generar_pdf_lineas($pdf_doc, $lineas, $linea_actual, $lppag);

                $pdf_doc->set_y(90);
                $this->generar_pdf_totales($pdf_doc, $lineas_iva, $pagina);
                $pagina++;
            }
        } else {
            $pdf_doc->pdf->ezText('¡' . ucfirst(FS_ALBARAN) . ' sin líneas!', 20);
        }

        $pdf_doc->show(FS_ALBARAN . '_' . $this->documento->codigo . '.pdf');
    }

    public function generar_pdf_factura() {
        /// Creamos el PDF y escribimos sus metadatos
        $pdf_doc = new fs_pdf('a5');
        $pdf_doc->pdf->addInfo('Title', ucfirst(FS_FACTURA) . ' ' . $this->documento->codigo);
        $pdf_doc->pdf->addInfo('Subject', ucfirst(FS_FACTURA) . ' ' . $this->documento->codigo);
        $pdf_doc->pdf->addInfo('Author', $this->empresa->nombre);

        $lineas = $this->documento->get_lineas();
        $lineas_iva = $pdf_doc->get_lineas_iva($lineas);
        if ($lineas) {
            $linea_actual = 0;
            $pagina = 1;

            /// imprimimos las páginas necesarias
            while ($linea_actual < count($lineas)) {
                $lppag = 15;

                /// salto de página
                if ($linea_actual > 0) {
                    $pdf_doc->pdf->ezNewPage();
                }

                $pdf_doc->generar_pdf_cabecera($this->empresa, $lppag);
                $this->generar_pdf_datos_cliente($pdf_doc, $lppag);

                $this->generar_pdf_lineas($pdf_doc, $lineas, $linea_actual, $lppag, $this->documento);

                if ($linea_actual == count($lineas)) {
                    if (!$this->documento->pagada AND $this->impresion['print_formapago']) {
                        $fp0 = new forma_pago();
                        $forma_pago = $fp0->get($this->documento->codpago);
                        if ($forma_pago) {
                            $texto_pago = "\n<b>Forma de pago</b>: " . $forma_pago->descripcion;

                            if (!$forma_pago->imprimir) {
                                /// nada
                            } else if ($forma_pago->domiciliado) {
                                $cbc0 = new cuenta_banco_cliente();
                                $encontrada = FALSE;
                                foreach ($cbc0->all_from_cliente($this->documento->codcliente) as $cbc) {
                                    $texto_pago .= "\n<b>Domiciliado en</b>: ";
                                    if ($cbc->iban) {
                                        $texto_pago .= $cbc->iban(TRUE);
                                    }

                                    if ($cbc->swift) {
                                        $texto_pago .= "\n<b>SWIFT/BIC</b>: " . $cbc->swift;
                                    }
                                    $encontrada = TRUE;
                                    break;
                                }
                                if (!$encontrada) {
                                    $texto_pago .= "\n<b>El cliente no tiene cuenta bancaria asignada.</b>";
                                }
                            } else if ($forma_pago->codcuenta) {
                                $cb0 = new cuenta_banco();
                                $cuenta_banco = $cb0->get($forma_pago->codcuenta);
                                if ($cuenta_banco) {
                                    if ($cuenta_banco->iban) {
                                        $texto_pago .= "\n<b>IBAN</b>: " . $cuenta_banco->iban(TRUE);
                                    }

                                    if ($cuenta_banco->swift) {
                                        $texto_pago .= "\n<b>SWIFT o BIC</b>: " . $cuenta_banco->swift;
                                    }
                                }
                            }

                            $texto_pago .= "\n<b>Vencimiento</b>: " . $this->documento->vencimiento;
                            $pdf_doc->pdf->ezText($texto_pago, 9);
                        }
                    }
                }

                $pdf_doc->set_y(90);
                $this->generar_pdf_totales($pdf_doc, $lineas_iva, $pagina);

                /// pié de página para la factura
                if ($this->empresa->pie_factura) {
                    $pdf_doc->pdf->addText(10, 10, 8, $pdf_doc->center_text(fs_fix_html($this->empresa->pie_factura), 180));
                }

                $pagina++;
            }
        } else {
            $pdf_doc->pdf->ezText('¡' . ucfirst(FS_FACTURA) . ' sin líneas!', 20);
        }

        $pdf_doc->show(FS_FACTURA . '_' . $this->documento->codigo . '.pdf');
    }

    public function is_html($txt) {
        return ( $txt != strip_tags($txt) ) ? TRUE : FALSE;
    }

}
