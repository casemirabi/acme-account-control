<?php

/**
 * ============================================================
 * ACME - INSS TCPDF Service
 * ============================================================
 *
 * Arquivo completo para copiar e colar.
 *
 * Ajustes aplicados:
 * - remove a logo do topo
 * - mantém a logo apenas no rodapé
 * - corrige alinhamento do rodapé da página 1
 * - corrige erro de sintaxe em renderPage2()
 * - corrige chaves divergentes entre mapPayload() e drawPage1BenefitBlock()
 * - mantém compatibilidade com PHP 7.4+ e WordPress
 */

if (!class_exists('InssTcpdfService')) {
    class InssTcpdfService
    {
        /** @var string */
        private $fontArial = 'helvetica';

        /** @var string */
        private $fontRegular = 'helvetica';

        /** @var string */
        private $fontBold = 'helvetica';

        /** @var string */
        private $pluginBasePath = '';

        /** @var string */
        private $assetIconMoney = '';

        /** @var string */
        private $assetIconInss = '';

        /** @var string */
        private $assetIconPerson = '';

        /** @var string */
        private $assetQr = '';

        /**
         * Construtor.
         * Resolve caminhos do plugin e assets com fallback.
         */
        public function __construct()
        {
            $this->pluginBasePath = $this->resolvePluginBasePath();

            $this->assetIconMoney = $this->resolveExistingPath(array(
                $this->pluginBasePath . 'assets/pdf/dinheiro.PNG',
                $this->pluginBasePath . 'assets/pdf/dinheiro.png',
            ));

            $this->assetIconInss = $this->resolveExistingPath(array(
                $this->pluginBasePath . 'assets/pdf/inssdois.PNG',
                $this->pluginBasePath . 'assets/pdf/inssdois.png',
            ));

            $this->assetIconPerson = $this->resolveExistingPath(array(
                $this->pluginBasePath . 'assets/pdf/pessoa.PNG',
                $this->pluginBasePath . 'assets/pdf/pessoa.png',
            ));

            $this->assetQr = $this->resolveExistingPath(array(
                $this->pluginBasePath . 'assets/pdf/qrcodepng.PNG',
                $this->pluginBasePath . 'assets/pdf/qrcodepng.png',
                $this->pluginBasePath . 'assets/img/inss-qr-fixo.png',
            ));
        }

        /**
         * Gera e envia o PDF.
         *
         * @param array $row
         * @param array $dados
         * @param string $fileName
         * @param string $destination
         * @return string|void
         */
        /**
         * ============================================================
         * BLOCO: GERAÇÃO DO PDF
         * ============================================================
         *
         * O PDF agora nasce em modo horizontal (landscape).
         * Isso deixa o layout compatível com o modelo Java antigo,
         * que trabalha com página larga.
         */
        public function outputPdf(array $row, array $dados, $fileName = 'historico-inss.pdf', $destination = 'I')
        {
            if (!class_exists('TCPDF')) {
                throw new RuntimeException('TCPDF não está carregado.');
            }

            // Mapeia o payload bruto para um formato interno padronizado.
            $mapped = $this->mapPayload($row, $dados);

            // Calcula a quantidade total de páginas antes de renderizar.
            $totalPages = $this->calculateTotalPages($mapped);

            /**
             * ORIENTAÇÃO HORIZONTAL
             * L = Landscape
             * Unidade = pt
             * Formato = A4
             */
            $pdf = new TCPDF('L', 'pt', 'A4', true, 'UTF-8', false);

            $pdf->SetCreator('ACME Account Control');
            $pdf->SetAuthor('ACME');
            $pdf->SetTitle('Histórico de Empréstimo Consignado');
            $pdf->SetSubject('INSS');
            $pdf->SetKeywords('INSS, empréstimo, consignado');

            // Header/Footer nativos desativados porque o layout é manual.
            $pdf->SetPrintHeader(false);
            $pdf->SetPrintFooter(false);

            // Margens zeradas para controle absoluto por coordenadas.
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);

            // Registra fontes customizadas, com fallback para helvetica.
            $this->registerFonts();

            $currentPage = 1;

            // Página 1: capa/dados do benefício.
            $this->renderPage1($pdf, $mapped, $currentPage, $totalPages);
            $currentPage++;

            // Página 2: margem/resumo financeiro.
            $this->renderPage2($pdf, $mapped, $currentPage, $totalPages);
            $currentPage++;

            // Demais páginas: empréstimos e cartões.
            $currentPage = $this->renderLoanPages($pdf, $mapped, $currentPage, $totalPages);
            $currentPage = $this->renderCardPages($pdf, $mapped, $currentPage, $totalPages);

            // Limpa buffers para não corromper a saída binária do PDF.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            if ($destination === 'S') {
                return $pdf->Output($fileName, 'S');
            }

            $pdf->Output($fileName, $destination);
            exit;
        }
        /**
         * Resolve o path base do plugin.
         *
         * @return string
         */
        private function resolvePluginBasePath()
        {
            if (defined('ACME_PLUGIN_DIR') && ACME_PLUGIN_DIR) {
                return rtrim(ACME_PLUGIN_DIR, '/\\') . DIRECTORY_SEPARATOR;
            }

            if (defined('ACME_ACC_PATH') && ACME_ACC_PATH) {
                return rtrim(ACME_ACC_PATH, '/\\') . DIRECTORY_SEPARATOR;
            }

            return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR;
        }

        /**
         * Retorna o primeiro path existente.
         *
         * @param array $paths
         * @return string
         */
        private function resolveExistingPath(array $paths)
        {
            foreach ($paths as $path) {
                if (is_string($path) && $path !== '' && file_exists($path)) {
                    return $path;
                }
            }

            return '';
        }

        /**
         * Registra fontes customizadas com fallback.
         *
         * @return void
         */
        private function registerFonts()
        {
            $fontArialPath = $this->resolveExistingPath(array(
                $this->pluginBasePath . 'assets/fonts/ARIALMT.ttf',
            ));

            $fontRegularPath = $this->resolveExistingPath(array(
                $this->pluginBasePath . 'assets/fonts/DejaVuSans.ttf',
            ));

            $fontBoldPath = $this->resolveExistingPath(array(
                $this->pluginBasePath . 'assets/fonts/DejaVuSans-Bold.ttf',
            ));

            if ($fontArialPath !== '' && class_exists('TCPDF_FONTS')) {
                $font = TCPDF_FONTS::addTTFfont($fontArialPath, 'TrueTypeUnicode', '', 96);
                if (!empty($font)) {
                    $this->fontArial = $font;
                }
            }

            if ($fontRegularPath !== '' && class_exists('TCPDF_FONTS')) {
                $font = TCPDF_FONTS::addTTFfont($fontRegularPath, 'TrueTypeUnicode', '', 96);
                if (!empty($font)) {
                    $this->fontRegular = $font;
                }
            }

            if ($fontBoldPath !== '' && class_exists('TCPDF_FONTS')) {
                $font = TCPDF_FONTS::addTTFfont($fontBoldPath, 'TrueTypeUnicode', '', 96);
                if (!empty($font)) {
                    $this->fontBold = $font;
                }
            }
        }

        /**
         * Mapeia o payload do INSS para um formato interno.
         *
         * @param array $row
         * @param array $dados
         * @return array
         */
        private function mapPayload(array $row, array $dados)
        {
            $beneficioData = isset($dados['dados']) && is_array($dados['dados'])
                ? $dados['dados']
                : $dados;

            $contratosEmprestimo = $this->normalizeArray($this->getFirst($dados, array(
                'contratosEmprestimo',
                'contratos',
                'emprestimos',
                'beneficio.contratosEmprestimo',
                'data.contratosEmprestimo',
            ), array()));

            $contratosRmc = $this->normalizeArray($this->getFirst($dados, array(
                'contratosRMC',
                'cartoesRMC',
                'contratosDeCartao',
                'beneficio.contratosRMC',
                'data.contratosRMC',
            ), array()));

            $contratosRcc = $this->normalizeArray($this->getFirst($dados, array(
                'contratosRCC',
                'cartoesRCC',
                'contratosDeCartaoRCC',
                'beneficio.contratosRCC',
                'data.contratosRCC',
            ), array()));

            $nome = $this->safeText($this->getFirst($beneficioData, array(
                'nome',
                'nomeBeneficiario',
                'beneficiario.nome',
                'pessoa.nome',
                'dadosBeneficio.nome',
                'data.nome',
                'data.nomeBeneficiario',
                'retorno.nome',
                'retorno.nomeBeneficiario',
            ), '—'));

            $beneficioDescricao = $this->safeText($this->getFirst($beneficioData, array(
                'especie.descricao',
                'descricaoEspecieBeneficio',
                'beneficioDescricao',
                'tipoBeneficio',
                'dadosBeneficio.descricao',
                'data.especie.descricao',
                'data.descricaoEspecieBeneficio',
                'retorno.especie.descricao',
            ), '—'));

            $numeroBeneficio = $this->formatBenefitNumber($this->getFirst($beneficioData, array(
                'beneficio',
                'numeroBeneficio',
                'nb',
                'beneficio.nb',
                'dadosBeneficio.nb',
                'data.nb',
                'data.numeroBeneficio',
                'retorno.nb',
            ), ''));

            $situacao = $this->safeText($this->getFirst($beneficioData, array(
                'situacao',
                'descricaoSituacaoBeneficio',
                'statusBeneficio',
                'dadosBeneficio.situacao',
                'data.situacao',
                'retorno.situacao',
            ), 'ATIVO'));

            $bancoPagamento = $this->bankDescription($this->getFirst($beneficioData, array(
                'banco',
                'bancoPagamento',
                'ifPagadora',
                'nomeIFPagadora',
                'dadosBancarios.banco',
                'dadosPagamento.banco',
                'data.bancoPagamento',
                'retorno.bancoPagamento',
            ), '—'));

            $meioPagamento = $this->safeText($this->getFirst($beneficioData, array(
                'meioPagamento',
                'formaPagamento',
                'dadosBancarios.formaCredito',
                'dadosPagamento.formaCredito',
                'tipo',
                'cbcPagadora',
                'data.meioPagamento',
                'retorno.meioPagamento',
            ), '—'));

            $agencia = $this->safeText($this->getFirst($beneficioData, array(
                'agencia',
                'codigoAgencia',
                'dadosBancarios.agencia',
                'dadosPagamento.agencia',
                'ag',
                'data.agencia',
                'retorno.agencia',
            ), '—'));

            $conta = $this->safeText($this->getFirst($beneficioData, array(
                'conta',
                'contaCorrente',
                'dadosBancarios.conta',
                'dadosPagamento.conta',
                'cc',
                'data.conta',
                'retorno.conta',
            ), '—'));

            if ($numeroBeneficio === '—') {
                $nbContrato = $this->extractBenefitNumberFromContracts($contratosEmprestimo, $contratosRmc, $contratosRcc);
                if ($nbContrato !== '') {
                    $numeroBeneficio = $this->formatBenefitNumber($nbContrato);
                }
            }

            $possuiProcurador = $this->booleanDescription(
                $this->getFirst($dados, array(
                    'possuiProcurador',
                    'procurador',
                    'data.possuiProcurador',
                ), false),
                'Possui procurador',
                'Não possui procurador'
            );

            $possuiRepresentante = $this->booleanDescription(
                $this->getFirst($dados, array(
                    'possuiRepresentante',
                    'possuiRepresentanteLegal',
                    'representanteLegal',
                    'data.possuiRepresentante',
                ), false),
                'Possui representante legal',
                'Não possui representante legal'
            );

            $temPensao = $this->getFirst($dados, array(
                'pensaoAlimenticia',
                'data.pensaoAlimenticia',
            ), false);

            $pensao = $this->isTruthy($temPensao) ? 'Com pensão alimentícia' : 'Pensão alimentícia';

            $elegivel = $this->booleanDescription(
                $this->getFirst($dados, array(
                    'elegivelEmprestimo',
                    'beneficioElegivelParaEmprestimo',
                    'liberadoParaEmprestimo',
                    'data.elegivelEmprestimo',
                ), true),
                'Elegível para empréstimos',
                'Não elegível para empréstimos'
            );

            $ativos = (int) $this->getFirst($dados, array(
                'emprestimosAtivos',
                'quantitativo.ativos',
                'data.quantitativo.ativos',
            ), -1);

            if ($ativos < 0) {
                $ativos = $this->countByStatus($contratosEmprestimo, 'ativo')
                    + $this->countByStatus($contratosRmc, 'ativo')
                    + $this->countByStatus($contratosRcc, 'ativo');
            }

            $suspensos = (int) $this->getFirst($dados, array(
                'emprestimosSuspenso',
                'emprestimosSuspensos',
                'quantitativo.suspensos',
                'data.quantitativo.suspensos',
            ), -1);

            if ($suspensos < 0) {
                $suspensos = $this->countByStatus($contratosEmprestimo, 'suspenso')
                    + $this->countByStatus($contratosRmc, 'suspenso')
                    + $this->countByStatus($contratosRcc, 'suspenso');
            }

            $portabilidade = (int) $this->getFirst($dados, array(
                'emprestimosPortabilidade',
                'quantitativo.portabilidade',
                'data.quantitativo.portabilidade',
            ), 0);

            $refinanciamento = (int) $this->getFirst($dados, array(
                'emprestimosRefinanciamento',
                'quantitativo.refinanciamento',
                'data.quantitativo.refinanciamento',
            ), 0);

            $baseCalculo = $this->toFloat($this->getFirst($dados, array(
                'valorBase',
                'margemBase',
                'baseCalculo',
                'vlBaseMargem',
                'resumoFinanceiro.baseCalculo',
                'margens.baseCalculo',
                'data.baseCalculo',
                'data.resumoFinanceiro.baseCalculo',
                'retorno.baseCalculo',
            ), 0));

            $maxComprometimento = $this->toFloat($this->getFirst($dados, array(
                'maximoComprometimento',
                'valorMaximoComprometimento',
                'vlMaximoComprometido',
                'resumoFinanceiro.maximoComprometimento',
                'data.maximoComprometimento',
            ), 0));

            $totalComprometido = $this->toFloat($this->getFirst($dados, array(
                'totalComprometido',
                'vlComprometido',
                'resumoFinanceiro.totalComprometido',
                'data.totalComprometido',
            ), 0));

            $margemEmprestimo = $this->toFloat($this->getFirst($dados, array(
                'margemConsignavel',
                'vlMargemEmprestimo',
                'margens.emprestimo.consignavel',
                'data.margemConsignavel',
            ), 0));

            $margemUtilizadaEmprestimo = $this->toFloat($this->getFirst($dados, array(
                'margemUtilizadaEmprestimo',
                'vlMargemUtilizadaEmprestimo',
                'margens.emprestimo.utilizada',
                'data.margemUtilizadaEmprestimo',
            ), 0));

            $margemDisponivelEmprestimo = $this->toFloat($this->getFirst($dados, array(
                'margemDisponivelEmprestimo',
                'vlMargemDisponivelEmprestimo',
                'margens.emprestimo.disponivel',
                'data.margemDisponivelEmprestimo',
            ), 0));

            $margemRmc = $this->toFloat($this->getFirst($dados, array(
                'margemRmc',
                'margemUtilizadaRmc',
                'vlMargemCartao',
                'margens.rmc.utilizada',
                'margemReservadaRMC',
                'vlMargemReservadaRMC',
                'data.margemRmc',
            ), 0));

            $margemRcc = $this->toFloat($this->getFirst($dados, array(
                'margemRcc',
                'margemUtilizadaRcc',
                'vlMargemCartaoRCC',
                'margens.rcc.utilizada',
                'margemReservadaRCC',
                'vlMargemReservadaRCC',
                'limiteRcc',
                'LimiteRcc',
                'data.margemRcc',
            ), 0));

            if ($margemUtilizadaEmprestimo <= 0) {
                $margemUtilizadaEmprestimo = $this->sumField($contratosEmprestimo, array(
                    'valorParcela',
                    'parcela',
                ));
            }

            if ($margemRmc <= 0) {
                $margemRmc = $this->sumField($contratosRmc, array(
                    'valorReservado',
                    'reservado',
                ));
            }

            if ($margemRcc <= 0) {
                $margemRcc = $this->sumField($contratosRcc, array(
                    'valorReservado',
                    'reservado',
                ));
            }

            if ($totalComprometido <= 0) {
                $totalComprometido = $margemUtilizadaEmprestimo + $margemRmc + $margemRcc;
            }

            if ($baseCalculo > 0 && $maxComprometimento <= 0) {
                $maxComprometimento = round($baseCalculo * 0.45, 2);
            }

            if ($baseCalculo > 0 && $margemEmprestimo <= 0) {
                $margemEmprestimo = round($baseCalculo * 0.35, 2);
            }

            if ($margemEmprestimo > 0 && $margemDisponivelEmprestimo <= 0) {
                $margemDisponivelEmprestimo = max($margemEmprestimo - $margemUtilizadaEmprestimo, 0);
            }

            $limiteCartao = ($baseCalculo > 0) ? round($baseCalculo * 0.05, 2) : 0.0;

            if ($baseCalculo <= 0) {
                if ($margemEmprestimo > 0) {
                    $baseCalculo = round($margemEmprestimo / 0.35, 2);
                } elseif ($limiteCartao > 0) {
                    $baseCalculo = round($limiteCartao / 0.05, 2);
                }
            }

            if ($baseCalculo > 0 && $limiteCartao <= 0) {
                $limiteCartao = round($baseCalculo * 0.05, 2);
            }

            if ($baseCalculo > 0 && $maxComprometimento <= 0) {
                $maxComprometimento = round($baseCalculo * 0.45, 2);
            }

            $geradoEm = $this->formatDateTime(
                isset($row['completed_at']) ? $row['completed_at']
                    : (isset($row['updated_at']) ? $row['updated_at']
                        : (isset($row['created_at']) ? $row['created_at'] : current_time('mysql')))
            );

            return array(
                'nome' => $nome,
                'beneficio_descricao' => $beneficioDescricao,
                'numero_beneficio' => $numeroBeneficio,
                'situacao' => $situacao,
                'banco_pagamento' => $bancoPagamento,
                'meio_pagamento' => $meioPagamento,
                'agencia' => $agencia,
                'conta' => $conta,
                'possui_procurador' => $possuiProcurador,
                'possui_representante' => $possuiRepresentante,
                'pensao' => $pensao,
                'elegivel' => $elegivel,
                'ativos' => $ativos,
                'suspensos' => $suspensos,
                'portabilidade' => $portabilidade,
                'refinanciamento' => $refinanciamento,
                'base_calculo' => $baseCalculo,
                'max_comprometimento' => $maxComprometimento,
                'total_comprometido' => $totalComprometido,
                'margem_emprestimo' => $margemEmprestimo,
                'margem_utilizada_emprestimo' => $margemUtilizadaEmprestimo,
                'margem_disponivel_emprestimo' => $margemDisponivelEmprestimo,
                'margem_rmc' => $margemRmc,
                'margem_rcc' => $margemRcc,
                'margem_limite_cartao' => $limiteCartao,
                'contratos_emprestimo' => $contratosEmprestimo,
                'contratos_rmc' => $contratosRmc,
                'contratos_rcc' => $contratosRcc,
                'gerado_em' => $geradoEm,
                'codigo_autenticidade' => '231026LVL66U38M-8MTZ24',
            );
        }

        /**
         * Calcula total de páginas.
         *
         * @param array $data
         * @return int
         */
        private function calculateTotalPages(array $data)
        {
            $loanContracts = isset($data['contratos_emprestimo']) && is_array($data['contratos_emprestimo'])
                ? $data['contratos_emprestimo']
                : array();

            $loanPages = max(1, (int) ceil(count($loanContracts) / 8));

            return 2 + $loanPages + 1;
        }

        /**
         * Cabeçalho da página 1.
         * Logo do topo removida.
         *
         * @param TCPDF $pdf
         * @param array $data
         * @return void
         */
        private function drawPage1Header(TCPDF $pdf, array $data)
        {
            $pageWidth = $pdf->GetPageWidth();

            // Barra superior cinza
            $pdf->SetFillColor(62, 62, 62);
            $pdf->Rect(0, 0, $pageWidth, 26, 'F');

            // Faixa azul central
            $faixaTopoWidth = 270.16;
            $xFaixaTopo = ($pageWidth - $faixaTopoWidth) / 2;

            $pdf->SetFillColor(0, 84, 178);
            $pdf->Rect($xFaixaTopo, 0, $faixaTopoWidth, 26, 'F');

            // Texto topo
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont($this->fontArial, '', 11);

            $textoTopo = 'Instituto Nacional do Seguro Social';

            // Centralização horizontal (mantém)
            $xTextoTopo = ($pageWidth - $pdf->GetStringWidth($textoTopo)) / 2;

            // 👉 CENTRALIZAÇÃO VERTICAL REAL
            $yTextoTopo = (26 - 11) / 2 + 1;

            $pdf->Text($xTextoTopo, $yTextoTopo, $textoTopo);
            // Reset cor
            $pdf->SetTextColor(0, 0, 0);

            // HISTÓRICO DE
            $pdf->SetFont($this->fontRegular, '', 10.5);
            $txt1 = 'HISTÓRICO DE';
            $x1 = ($pageWidth - $pdf->GetStringWidth($txt1)) / 2;
            $pdf->Text($x1, 40, $txt1);

            // TÍTULO
            $pdf->SetFont($this->fontBold, '', 13.2);
            $txt2 = 'EMPRÉSTIMO CONSIGNADO';
            $x2 = ($pageWidth - $pdf->GetStringWidth($txt2)) / 2;
            $pdf->Text($x2, 56, $txt2);

            // Linha
            $pdf->SetDrawColor(189, 191, 193);
            $pdf->SetLineWidth(1);
            $pdf->Line(20, 88, $pageWidth - 20, 88);

            // Faixa azul menor e centralizada
            $faixaWidth = 40; // diminuída (antes era 60)
            $xFaixa = ($pageWidth - $faixaWidth) / 2;

            $pdf->SetFillColor(0, 84, 178);
            $pdf->Rect($xFaixa, 86.8, $faixaWidth, 3.2, 'F');

            // Nome centralizado abaixo da linha
            // Nome centralizado abaixo da linha
            $nome = $this->safeText($data['nome'] ?? '—');
            $pdf->SetFont($this->fontRegular, '', 8.2);

            $xNome = ($pageWidth - $pdf->GetStringWidth($nome)) / 2;
            $pdf->Text($xNome, 96, $nome);
        }

        /**
         * Bloco Benefício da página 1.
         *
         * @param TCPDF $pdf
         * @param array $data
         * @return void
         */
        /**
         * ============================================================
         * BLOCO: BENEFÍCIO - PÁGINA 1
         * ============================================================
         *
         * Ajustes:
         * - largura maior do bloco
         * - fontes maiores
         * - melhor distribuição entre coluna esquerda e direita
         * - mais respiro visual
         */
        private function drawPage1BenefitBlock(TCPDF $pdf, array $data)
        {
            // Faixa cinza do título do bloco
            $pdf->SetFillColor(225, 228, 232);
            $pdf->Rect(20, 116, 760, 28, 'F');

            // Container do conteúdo
            $pdf->SetDrawColor(225, 228, 232);
            $pdf->SetLineWidth(1);
            $pdf->Rect(20, 144, 760, 155, 'D');

            // Ícone
            if (!empty($this->assetIconPerson) && file_exists($this->assetIconPerson)) {
                $pdf->Image($this->assetIconPerson, 22, 118, 24, 24, 'PNG');
            }

            // Título do bloco
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont($this->fontBold, '', 9.5);
            $pdf->Text(52, 125, 'Benefício');

            // Nome da espécie
            $beneficioDescricao = $this->safeText($data['beneficio_descricao'] ?? '—');
            $pdf->SetTextColor(0, 84, 178);
            $pdf->SetFont($this->fontBold, '', 8.6);
            $pdf->Text(28, 170, $beneficioDescricao);

            $pdf->SetTextColor(0, 0, 0);

            /**
             * Colunas do conteúdo
             * esquerda = rótulos e valores principais
             * direita  = status adicionais
             */
            $leftLabelX = 28;
            $leftValueX = 108;
            $rightX = 455;

            $baseY = 188;
            $lineGap = 17;

            // Rótulos da coluna esquerda
            $pdf->SetFont($this->fontBold, '', 7.8);
            $pdf->Text($leftLabelX, $baseY, 'Nº Benefício:');
            $pdf->Text($leftLabelX, $baseY + $lineGap, 'Situação:');
            $pdf->Text($leftLabelX, $baseY + ($lineGap * 2), 'Pago em:');
            $pdf->Text($leftLabelX, $baseY + ($lineGap * 3), 'Meio:');
            $pdf->Text($leftLabelX, $baseY + ($lineGap * 4), 'Agência:');
            $pdf->Text($leftLabelX, $baseY + ($lineGap * 5), 'Conta Corrente:');

            // Valores da coluna esquerda
            $pdf->SetFont($this->fontRegular, '', 7.8);
            $pdf->Text($leftValueX, $baseY, $this->safeText($data['numero_beneficio'] ?? '—'));
            $pdf->Text($leftValueX, $baseY + $lineGap, $this->safeText($data['situacao'] ?? 'ATIVO'));
            $pdf->Text($leftValueX, $baseY + ($lineGap * 2), $this->safeText($data['banco_pagamento'] ?? '—'));
            $pdf->Text($leftValueX, $baseY + ($lineGap * 3), $this->safeText($data['meio_pagamento'] ?? '—'));
            $pdf->Text($leftValueX, $baseY + ($lineGap * 4), $this->safeText($data['agencia'] ?? '—'));
            $pdf->Text($leftValueX, $baseY + ($lineGap * 5), $this->safeText($data['conta'] ?? '—'));

            // Coluna direita
            $pdf->SetFont($this->fontRegular, '', 7.8);
            $pdf->Text($rightX, $baseY, $this->safeText($data['possui_procurador'] ?? 'Não possui procurador'));
            $pdf->Text($rightX, $baseY + $lineGap, $this->safeText($data['possui_representante'] ?? 'Não possui representante legal'));
            $pdf->Text($rightX, $baseY + ($lineGap * 2), $this->safeText($data['pensao'] ?? 'Pensão alimentícia'));
            $pdf->Text($rightX, $baseY + ($lineGap * 3), 'Liberado para empréstimo');

            $pdf->SetFont($this->fontBold, '', 7.8);
            $pdf->Text($rightX, $baseY + ($lineGap * 4), $this->safeText($data['elegivel'] ?? 'Elegível para empréstimos'));
        }

        /**
         * Bloco Quantitativo da página 1.
         *
         * @param TCPDF $pdf
         * @param array $data
         * @return void
         */
        /**
         * ============================================================
         * BLOCO: QUANTITATIVO - PÁGINA 1
         * ============================================================
         *
         * Ajustes:
         * - bloco mais largo
         * - tabela central maior
         * - fontes maiores
         * - coluna de quantidade mais proporcional
         */
        private function drawPage1QuantitativoBlock(TCPDF $pdf, array $data)
        {
            // Faixa do título
            $pdf->SetFillColor(225, 228, 232);
            $pdf->Rect(20, 306, 760, 28, 'F');

            // Container externo
            $pdf->SetDrawColor(225, 228, 232);
            $pdf->Rect(20, 334, 760, 135, 'D');

            // Ícone
            if (!empty($this->assetIconMoney) && file_exists($this->assetIconMoney)) {
                $pdf->Image($this->assetIconMoney, 22, 308, 24, 24, 'PNG');
            }

            // Título
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont($this->fontBold, '', 9.5);
            $pdf->Text(52, 314, 'Quantitativo de Empréstimos por Situação');

            /**
             * Tabela central
             */
            $tableWidth = 360;
            $tableX = ($pdf->GetPageWidth() - $tableWidth) / 2;
            $tableY = 372;

            $leftW  = 255;
            $rightW = 105;
            $rowH   = 18;

            $rows = array(
                array('SITUAÇÃO', 'QUANTIDADE', true),
                array('ATIVOS', (string) ($data['ativos'] ?? '0'), false),
                array('SUSPENSOS', (string) ($data['suspensos'] ?? '0'), false),
                array('RESERVADOS PORTABILIDADE', (string) ($data['portabilidade'] ?? '0'), false),
                array('RESERVADOS REFINANCIAMENTO', (string) ($data['refinanciamento'] ?? '0'), false),
            );

            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.8);

            foreach ($rows as $index => $row) {
                $currentY = $tableY + ($index * $rowH);

                if ($index === 0) {
                    // Header: ambas cinza
                    $pdf->SetFillColor(230, 230, 230);
                    $pdf->Rect($tableX, $currentY, $leftW, $rowH, 'FD');
                    $pdf->Rect($tableX + $leftW, $currentY, $rightW, $rowH, 'FD');
                } else {
                    // Esquerda cinza
                    $pdf->SetFillColor(230, 230, 230);
                    $pdf->Rect($tableX, $currentY, $leftW, $rowH, 'FD');

                    // Direita branca
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->Rect($tableX + $leftW, $currentY, $rightW, $rowH, 'FD');
                }

                if ($row[2] === true) {
                    // Cabeçalho
                    $pdf->SetFont($this->fontBold, '', 7.5);

                    $pdf->SetXY($tableX, $currentY + 4);
                    $pdf->Cell($leftW, 6, $row[0], 0, 0, 'C', false);

                    $pdf->SetXY($tableX + $leftW, $currentY + 4);
                    $pdf->Cell($rightW, 6, $row[1], 0, 0, 'C', false);
                } else {
                    // Situação
                    $pdf->SetFont($this->fontBold, '', 7.5);
                    $pdf->SetXY($tableX + 8, $currentY + 4);
                    $pdf->Cell($leftW - 16, 6, $row[0], 0, 0, 'L', false);

                    // Quantidade
                    $pdf->SetFont($this->fontRegular, '', 7.5);
                    $pdf->SetXY($tableX + $leftW, $currentY + 4);
                    $pdf->Cell($rightW, 6, $row[1], 0, 0, 'C', false);
                }
            }
        }

        /**
         * Rodapé da página 1.
         * Logo somente no rodapé.
         *
         * @param TCPDF $pdf
         * @param array $data
         * @param int $currentPage
         * @param int $totalPages
         * @return void
         */
        /**
         * ============================================================
         * BLOCO: RODAPÉ DA PÁGINA 1
         * ============================================================
         *
         * Ajustado para horizontal.
         */
        private function drawPage1Footer(TCPDF $pdf, array $data, $currentPage, $totalPages)
        {
            $pageWidth  = $pdf->GetPageWidth();
            $pageHeight = $pdf->GetPageHeight();

            // MAIS PARA BAIXO
            $lineY = $pageHeight - 70;

            // linha cinza
            $pdf->SetDrawColor(189, 191, 193);
            $pdf->SetLineWidth(1);
            $pdf->Line(20, $lineY, $pageWidth - 20, $lineY);

            // marcador azul (corrigido e centralizado)
            $markerWidth = 40;
            $markerX = ($pageWidth - $markerWidth) / 2;

            $pdf->SetFillColor(0, 84, 178);
            $pdf->Rect($markerX, $lineY - 2, $markerWidth, 4, 'F');

            // QR Code
            if (!empty($this->assetQr) && file_exists($this->assetQr)) {
                $pdf->Image($this->assetQr, 28, $pageHeight - 60, 34, 34, 'PNG');
            }

            // texto do QR (fonte maior)
            $codigoAutenticidade = $this->safeText($data['codigo_autenticidade'] ?? '231026LVL66U38M-8MTZ24');

            $pdf->SetFont($this->fontRegular, '', 7.4);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Text(70, $pageHeight - 55, 'Você pode conferir a autenticidade do documento em');
            $pdf->Text(70, $pageHeight - 47, 'https://meu.inss.gov.br/central/#/autenticidade');
            $pdf->Text(70, $pageHeight - 39, 'com o código ' . $codigoAutenticidade);

            // logo INSS
            if (!empty($this->assetIconInss) && file_exists($this->assetIconInss)) {
                $pdf->Image($this->assetIconInss, $pageWidth - 80, $pageHeight - 60, 48, 23, 'PNG');
            }

            // data (maior)
            $geradoEm = $this->safeText($data['gerado_em'] ?? date('d/m/Y H:i:s'));
            $pdf->SetFont($this->fontRegular, '', 8);
            // data (um pouco mais para cima)
            $pdf->SetFont($this->fontRegular, '', 8);
            $pdf->Text($pageWidth - 105, $pageHeight - 32, $geradoEm);

            // paginação (embaixo da data)
            $pdf->Text($pageWidth - 45, $pageHeight - 20, $currentPage . ' / ' . $totalPages);
        }

        /**
         * Página 1.
         *
         * @param TCPDF $pdf
         * @param array $data
         * @param int $currentPage
         * @param int $totalPages
         * @return void
         */
        /**
         * ============================================================
         * BLOCO: RENDER DA PÁGINA 1
         * ============================================================
         *
         * A página 1 agora é criada explicitamente em horizontal.
         */
        private function renderPage1(TCPDF $pdf, array $data, $currentPage, $totalPages)
        {
            // Cria a página em landscape.
            $pdf->AddPage('L');

            // Blocos da página 1.
            $this->drawPage1Header($pdf, $data);
            $this->drawPage1BenefitBlock($pdf, $data);
            $this->drawPage1QuantitativoBlock($pdf, $data);
            $this->drawPage1Footer($pdf, $data, $currentPage, $totalPages);

            // Blocos da página 1.
            $this->drawPage1Header($pdf, $data);
            $this->drawPage1BenefitBlock($pdf, $data);
            $this->drawPage1QuantitativoBlock($pdf, $data);
            $this->drawPage1Footer($pdf, $data, $currentPage, $totalPages);
        }

        /**
         * Página 2.
         *
         * @param TCPDF $pdf
         * @param array $data
         * @param int $currentPage
         * @param int $totalPages
         * @return void
         */

        /**
         * ============================================================
         * BLOCO: RENDER DA PÁGINA 2
         * ============================================================
         *
         * Página 2 com:
         * - orientação horizontal
         * - faixa superior cinza
         * - container contínuo abaixo da faixa
         * - tabela "VALORES DO BENEFÍCIO" com cabeçalho unificado
         * - tabela de modalidades com espaçamento correto
         * - sem duplicação de título e sem tabela repetida
         */
        private function renderPage2(TCPDF $pdf, array $data, $currentPage, $totalPages)
        {
            // Cria a página em landscape.
            $pdf->AddPage('L');

            // Cabeçalho superior padrão das páginas internas.
            $this->drawTopHeader($pdf);

            // Medidas dinâmicas da página horizontal.
            $pageWidth  = $pdf->GetPageWidth();
            $pageHeight = $pdf->GetPageHeight();

            /**
             * ========================================================
             * BLOCO: FAIXA SUPERIOR CINZA
             * ========================================================
             *
             * Essa faixa recebe o ícone do dinheiro e o título da seção.
             */
            $y = 30;
            $h = 28;

            $pdf->SetFillColor(225, 228, 232);
            $pdf->Rect(20, $y, $pageWidth - 40, $h, 'F');

            // Configuração do ícone.
            $padding = 2;
            $imgSize = 24;

            $imgX = 20 + $padding;
            $imgY = $y + (($h - $imgSize) / 2);

            // Desenha o ícone da seção, se existir.
            if ($this->assetIconMoney !== '' && file_exists($this->assetIconMoney)) {
                $pdf->Image($this->assetIconMoney, $imgX, $imgY, $imgSize, $imgSize, 'PNG');
            }

            /**
             * ========================================================
             * BLOCO: TÍTULO DA FAIXA
             * ========================================================
             *
             * O texto fica centralizado verticalmente em relação à faixa.
             */
            $textX = $imgX + $imgSize + 8;
            $textWidth = ($pageWidth - 20) - $textX;

            $pdf->SetFont($this->fontBold, '', 10);
            $pdf->SetTextColor(0, 0, 0);

            $texto = 'Margem para Empréstimo/Cartão e Resumo Financeiro';

            $textHeight = $pdf->getStringHeight($textWidth, $texto);
            $textY = $y + (($h - $textHeight) / 2);

            $pdf->SetXY($textX, $textY);
            $pdf->MultiCell(
                $textWidth,
                $textHeight,
                $texto,
                0,
                'L',
                false,
                1
            );

            /**
             * ========================================================
             * BLOCO: CONTAINER INFERIOR CONTÍNUO
             * ========================================================
             *
             * A borda nasce da faixa cinza, então não desenhamos a linha
             * de cima. Só esquerda, direita e base.
             */
            $containerX = 20;
            $containerY = $y + $h;
            $containerWidth = $pageWidth - 40;
            $containerHeight = 380;

            $pdf->SetDrawColor(180, 180, 180);
            $pdf->SetLineWidth(0.6);

            // Lateral esquerda.
            $pdf->Line($containerX, $containerY, $containerX, $containerY + $containerHeight);

            // Lateral direita.
            $pdf->Line($containerX + $containerWidth, $containerY, $containerX + $containerWidth, $containerY + $containerHeight);

            // Linha inferior.
            $pdf->Line($containerX, $containerY + $containerHeight, $containerX + $containerWidth, $containerY + $containerHeight);

            /**
             * ========================================================
             * BLOCO: TABELA SUPERIOR - VALORES DO BENEFÍCIO
             * ========================================================
             */
            $startY = $containerY + 25; // margem dinâmica e consistente
            $spacing = 15;

            $this->drawSimpleTable(
                $pdf,
                62,
                $startY,
                array(
                    array('VALORES DO BENEFÍCIO', ''),
                    array('BASE DE CÁLCULO', $this->money($data['base_calculo'])),
                    array('MÁXIMO DE COMPROMETIMENTO PERMITIDO', $this->money($data['max_comprometimento'])),
                    array('TOTAL COMPROMETIDO', $this->money($data['total_comprometido'])),
                    array('MARGEM EXTRAPOLADA***', 'R$0,00'),
                ),
                array(270, 145),
                18,
                true,
                true
            );

            // Cálculos auxiliares de disponibilidade por modalidade.
            $rmcDisponivel = max($data['margem_limite_cartao'] - $data['margem_rmc'], 0);
            $rccDisponivel = max($data['margem_limite_cartao'] - $data['margem_rcc'], 0);

            // Altura da primeira tabela para posicionar a segunda corretamente.
            $table1Rows = 5;
            $rowHeight = 18;
            $table1Height = $table1Rows * $rowHeight;
            $nextY = $startY + $table1Height + $spacing;

            /**
             * ========================================================
             * BLOCO: TABELA INFERIOR ESQUERDA - RÓTULOS
             * ========================================================
             */
            $this->drawSimpleTable(
                $pdf,
                62,
                $nextY,
                array(
                    array(''),
                    array('BASE DE CÁLCULO'),
                    array('MARGEM CONSIGNÁVEL*'),
                    array('MARGEM UTILIZADA'),
                    array('MARGEM RESERVADA**'),
                    array('MARGEM DISPONÍVEL'),
                    array('MARGEM EXTRAPOLADA***'),
                ),
                array(188),
                18,
                true,
                false
            );

            /**
             * ========================================================
             * BLOCO: TABELA INFERIOR DIREITA - VALORES POR MODALIDADE
             * ========================================================
             */
            $this->drawSimpleTable(
                $pdf,
                250,
                $nextY,
                array(
                    array('EMPRÉSTIMOS', 'RMC', 'RCC'),
                    array($this->money($data['base_calculo']), $this->money($data['base_calculo']), $this->money($data['base_calculo'])),
                    array($this->money($data['margem_emprestimo']), $this->money($data['margem_limite_cartao']), $this->money($data['margem_limite_cartao'])),
                    array($this->money($data['margem_utilizada_emprestimo']), $this->money($data['margem_rmc']), $this->money($data['margem_rcc'])),
                    array('R$0,00', '-', '-'),
                    array($this->money($data['margem_disponivel_emprestimo']), $this->money($rmcDisponivel), $this->money($rccDisponivel)),
                    array('R$0,00', 'R$0,00', 'R$0,00'),
                ),
                array(108, 108, 108),
                18,
                true,
                false
            );

            $table2Rows = 7; // número de linhas da tabela inferior
            $table2Height = $table2Rows * $rowHeight;

            // posição final da segunda tabela
            $notesStartY = $nextY + $table2Height + 12; // margem inferior elegante

            /**
             * ========================================================
             * BLOCO: OBSERVAÇÕES/NOTAS DE RODAPÉ DA PÁGINA 2
             * ========================================================
             */

            $notesX = 62;
            $notesWidth = 712; // largura exata da tabela

            $this->writeWrappedText(
                $pdf,
                62,
                $notesStartY,
                $notesWidth,
                12,
                $this->fontRegular,
                8,
                '* Para benefícios das espécies, 18, 87 e 88 a margem consignável representa 30% da base de cálculo para empréstimos e 5% para cartão, podendo optar por somente uma das modalidades RMC ou RCC. Para as demais espécies, a margem consignável atual representa 35% da base de cálculo para empréstimos, 10% para cartão, sendo 5% para RMC e 5% para RCC.'
            );

            $this->writeWrappedText(
                $pdf,
                62,
                $notesStartY + 32,
                $notesWidth,
                12,
                $this->fontRegular,
                8,
                '** O valor da margem reservada está incluído no valor da margem utilizada.'
            );

            $this->writeWrappedText(
                $pdf,
                62,
                $notesStartY + 42,
                $notesWidth,
                12,
                $this->fontRegular,
                8,
                '*** A margem extrapolada representa o valor que excedeu a margem disponível da modalidade ou o máximo de comprometimento do benefício, que pode ocorrer em situações específicas como a redução da renda do benefício ou a alteração legal da margem consignável de empréstimos e cartões.'
            );

            // Marcador e rodapé padrão das páginas internas.
            $this->drawBottomMarker($pdf);
            $this->drawFooter($pdf, $data['gerado_em'], $currentPage . ' / ' . $totalPages);
        }

        /**
         * Página(s) de empréstimos.
         *
         * @param TCPDF $pdf
         * @param array $data
         * @param int $currentPage
         * @param int $totalPages
         * @return int
         */
        /**
         * ============================================================
         * BLOCO: PÁGINAS DE EMPRÉSTIMOS
         * ============================================================
         *
         * Todas as páginas internas de empréstimos também nascem em horizontal.
         */

        /**
         * ============================================================
         * BLOCO: PÁGINAS DE EMPRÉSTIMOS
         * ============================================================
         *
         * Ajustes aplicados:
         * - mantém a página em horizontal
         * - notas ficam logo abaixo da tabela
         * - quebra visual para dinheiro
         * - quebra visual para textos longos
         * - sem posições fixas para as observações
         */
        private function renderLoanPages(TCPDF $pdf, array $data, $currentPage, $totalPages)
        {
            $contracts = isset($data['contratos_emprestimo']) && is_array($data['contratos_emprestimo'])
                ? $data['contratos_emprestimo']
                : array();

            $chunks = !empty($contracts) ? array_chunk($contracts, 8) : array(array());

            /**
             * Quebra visual para valores monetários.
             * Ex.: R$20.659,71 -> R$\n20.659,71
             */
            $wrapMoney = function ($value) {
                $value = trim((string) $value);

                if ($value === '' || $value === '—') {
                    return '—';
                }

                return str_replace('R$', "R$\n", $value);
            };

            /**
             * Quebra visual simples para textos muito longos.
             */
            $wrapText = function ($value, $limit = 12) {
                $value = trim((string) $value);

                if ($value === '' || $value === '—') {
                    return '—';
                }

                return wordwrap($value, $limit, "\n", true);
            };

            foreach ($chunks as $chunk) {
                // Página em landscape.
                $pdf->AddPage('L');

                $this->drawTopHeader($pdf);

                // Título principal da página.
                $this->writeText($pdf, 20, 52, $this->fontBold, 14, 'EMPRÉSTIMOS BANCÁRIOS');

                /**
                 * ========================================================
                 * BLOCO: SUBTÍTULO PADRÃO CINZA
                 * ========================================================
                 */
                $subX = 10;
                $subY = 76;
                $subW = 818;
                $subH = 24;

                $pdf->SetFillColor(225, 228, 232);
                $pdf->Rect($subX, $subY, $subW, $subH, 'F');

                $pdf->SetDrawColor(120, 120, 120);
                $pdf->SetLineWidth(0.8);
                $pdf->Rect($subX, $subY, $subW, $subH, 'D');

                $pdf->SetFont($this->fontRegular, '', 10);
                $pdf->SetTextColor(140, 140, 140);

                $pdf->SetXY($subX + 6, $subY + 4.5);
                $pdf->Cell(
                    $subW - 12,
                    14,
                    'CONTRATOS ATIVOS E SUSPENSOS*',
                    0,
                    1,
                    'L',
                    false
                );

                /**
                 * ========================================================
                 * BLOCO: DEFINIÇÃO DAS COLUNAS
                 * ========================================================
                 */
                $columns = array(
                    array('label' => 'CONTRATO',      'width' => 40, 'align' => 'C'),
                    array('label' => 'BANCO',         'width' => 40, 'align' => 'C'),
                    array('label' => 'SITUAÇÃO',      'width' => 36, 'align' => 'C'),
                    array('label' => 'ORIGEM',        'width' => 44, 'align' => 'C'),
                    array('label' => 'DATA INCLUSÃO', 'width' => 44, 'align' => 'C'),
                    array('label' => 'INÍCIO',        'width' => 48, 'align' => 'C'),
                    array('label' => 'FIM',           'width' => 48, 'align' => 'C'),
                    array('label' => 'QTD',           'width' => 38, 'align' => 'C'),
                    array('label' => 'PARCELA',       'width' => 38, 'align' => 'C'),
                    array('label' => 'EMPRESTADO',    'width' => 40, 'align' => 'C'),
                    array('label' => 'LIBERADO',      'width' => 40, 'align' => 'C'),
                    array('label' => 'IOF',           'width' => 30, 'align' => 'C'),
                    array('label' => 'CET M',         'width' => 28, 'align' => 'C'),
                    array('label' => 'CET A',         'width' => 28, 'align' => 'C'),
                    array('label' => 'TX M',          'width' => 26, 'align' => 'C'),
                    array('label' => 'TX A',          'width' => 26, 'align' => 'C'),
                    array('label' => 'VALOR PAGO',    'width' => 38, 'align' => 'C'),
                    array('label' => '1º DESC.',      'width' => 42, 'align' => 'C'),
                    array('label' => 'SUSP. BANCO',   'width' => 36, 'align' => 'C'),
                    array('label' => 'SUSP. INSS',    'width' => 36, 'align' => 'C'),
                    array('label' => 'REAT. BANCO',   'width' => 36, 'align' => 'C'),
                    array('label' => 'REAT. INSS',    'width' => 36, 'align' => 'C'),
                );

                $rows = array();

                foreach ($chunk as $contract) {
                    $rows[] = array(
                        $wrapText($this->safeText($this->getFirst($contract, array('contrato', 'numeroContrato'), '—')), 14),
                        $wrapText($this->extractBankCode($this->getFirst($contract, array('banco'), '—')), 12),
                        $wrapText($this->safeText($this->getFirst($contract, array('situacao'), '—')), 10),
                        $wrapText($this->safeText($this->getFirst($contract, array('origemAverbacao'), '')), 10),
                        $this->formatDate($this->getFirst($contract, array('dataInclusao', 'dataInicio'), '')),
                        $this->formatMonthYear($this->getFirst($contract, array('competenciaInicioDesconto', 'competenciaInicio', 'dataInicio'), '')),
                        $this->formatMonthYear($this->getFirst($contract, array('competenciaFimDesconto', 'competenciaFim', 'dataFim'), '')),
                        $this->safeText($this->getFirst($contract, array('quantidadeParcelas'), '—')),
                        $wrapMoney($this->money($this->toFloat($this->getFirst($contract, array('valorParcela', 'parcela'), 0)))),
                        $wrapMoney($this->money($this->toFloat($this->getFirst($contract, array('valorEmprestado', 'emprestado'), 0)))),
                        $wrapMoney($this->money($this->toFloat($this->getFirst($contract, array('valorLiberado', 'liberado'), 0)))),
                        $wrapMoney($this->money($this->toFloat($this->getFirst($contract, array('iof'), 0)))),
                        $wrapText($this->safeText($this->getFirst($contract, array('cetMensal'), '')), 6),
                        $wrapText($this->safeText($this->getFirst($contract, array('cetAnual'), '')), 6),
                        $wrapText($this->safeText($this->getFirst($contract, array('taxaMensal', 'taxaJurosMensal'), '')), 6),
                        $wrapText($this->safeText($this->getFirst($contract, array('taxaAnual', 'taxaJurosAnual'), '')), 6),
                        $wrapMoney($this->money($this->toFloat($this->getFirst($contract, array('valorPago'), 0)))),
                        $this->formatDate($this->getFirst($contract, array('primeiroDesconto'), '')),
                        $this->formatDate($this->getFirst($contract, array('suspensaoBanco'), '')),
                        $this->formatDate($this->getFirst($contract, array('suspensaoInss'), '')),
                        $this->formatDate($this->getFirst($contract, array('reativacaoBanco'), '')),
                        $this->formatDate($this->getFirst($contract, array('reativacaoInss'), ''))
                    );
                }

                if (empty($rows)) {
                    $rows[] = array('Nenhum contrato encontrado.');
                }

                // Desenha a tabela e captura a posição final.
                $tableEndY = $this->drawGridTable($pdf, 10, 102, $columns, $rows, 40);

                // Observações logo abaixo da tabela.
                $notesY = $tableEndY + 10;

                $this->writeText(
                    $pdf,
                    10,
                    $notesY,
                    $this->fontRegular,
                    8,
                    '*Contratos que comprometem a margem consignável.'
                );

                $this->writeText(
                    $pdf,
                    10,
                    $notesY + 12,
                    $this->fontRegular,
                    8,
                    '**Valor pago a título de dívida do cliente (refinanciamento e portabilidade).'
                );

                $this->drawBottomMarker($pdf);
                $this->drawFooter($pdf, $data['gerado_em'], $currentPage . ' / ' . $totalPages);

                $currentPage++;
            }

            return $currentPage;
        }

        /**
         * Página de cartões.
         *
         * @param TCPDF $pdf
         * @param array $data
         * @param int $currentPage
         * @param int $totalPages
         * @return int
         */

        /**
         * ============================================================
         * BLOCO: PÁGINAS DE CARTÃO
         * ============================================================
         *
         * Página de cartões também em horizontal.
         */ private function renderCardPages(TCPDF $pdf, array $data, $currentPage, $totalPages)
        {
            $pdf->AddPage('L');
            $this->drawTopHeader($pdf);

            $this->writeText($pdf, 20, 52, $this->fontBold, 14, 'CARTÃO DE CRÉDITO');

            $columns = array(
                array('label' => "CONTRATO", 'width' => 76),
                array('label' => "BANCO", 'width' => 110),
                array('label' => "SITUAÇÃO", 'width' => 72),
                array('label' => "ORIGEM DA\nAVERBAÇÃO", 'width' => 92),
                array('label' => "DATA\nINCLUSÃO", 'width' => 52),
                array('label' => "LIMITE DE\nCARTÃO", 'width' => 78, 'align' => 'R'),
                array('label' => "RESERVADO", 'width' => 66, 'align' => 'R'),
                array('label' => "SUSPENSÃO\nBANCO", 'width' => 56),
                array('label' => "SUSPENSÃO\nINSS", 'width' => 56),
                array('label' => "REATIVAÇÃO\nBANCO", 'width' => 56),
                array('label' => "REATIVAÇÃO\nINSS", 'width' => 56),
            );

            // =========================
            // RMC
            // =========================
            $this->drawSectionTitle($pdf, 10, 82, 770, 22, 'CARTÃO DE CRÉDITO - RMC');

            // Faixa do subtítulo integrada ao bloco da tabela
            $pdf->SetDrawColor(170, 170, 170);
            $pdf->SetFillColor(232, 236, 240);
            $pdf->Rect(10, 104, 770, 20, 'DF');

            $pdf->SetTextColor(130, 130, 130);
            $pdf->SetFont($this->fontRegular, '', 9.5);
            $pdf->SetFont($this->fontRegular, '', 12);
            $pdf->SetXY(18, 108);
            $pdf->Cell(740, 10, 'CONTRATOS ATIVOS E SUSPENSOS*', 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);

            $afterTableY = $this->drawGridTable($pdf, 10, 124, $columns, $this->mapCardRows($data['contratos_rmc']), 40, 6, 3);

            $this->writeText($pdf, 10, $afterTableY + 6, $this->fontRegular, 8, '* Contratos que comprometem a margem consignável');

            // =========================
            // RCC
            // =========================
            $this->drawSectionTitle($pdf, 10, 330, 770, 22, 'CARTÃO DE CRÉDITO - RCC');

            // Faixa do subtítulo integrada ao bloco da tabela
            $pdf->SetDrawColor(170, 170, 170);
            $pdf->SetFillColor(232, 236, 240);
            $pdf->Rect(10, 352, 770, 20, 'DF');

            $pdf->SetTextColor(130, 130, 130);
            $pdf->SetFont($this->fontRegular, '', 9.5);
            $pdf->SetFont($this->fontRegular, '', 12);

            $pdf->SetXY(18, 356);
            $pdf->Cell(740, 10, 'CONTRATOS ATIVOS E SUSPENSOS*', 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);

            $afterTableY = $this->drawGridTable($pdf, 10, 372, $columns, $this->mapCardRows($data['contratos_rcc']), 40, 6, 3);

            $this->writeText($pdf, 10, $afterTableY + 6, $this->fontRegular, 8, '* Contratos que comprometem a margem consignável');

            $this->drawBottomMarker($pdf);
            $this->drawFooter($pdf, $data['gerado_em'], $currentPage . ' / ' . $totalPages);

            return $currentPage + 1;
        }

        /**
         * Mapeia linhas dos cartões.
         *
         * @param array $items
         * @return array
         */
        private function mapCardRows(array $items)
        {
            $rows = array();

            foreach ($items as $item) {
                $rows[] = array(
                    $this->safeText($this->getFirst($item, array('contrato', 'numeroContrato'), '—')),
                    $this->extractBankCode($this->getFirst($item, array('banco'), '—')),
                    $this->safeText($this->getFirst($item, array('situacao'), '—')),
                    $this->safeText($this->getFirst($item, array('origemAverbacao'), '')),
                    $this->formatDate($this->getFirst($item, array('dataInclusao'), '')),
                    $this->money($this->toFloat($this->getFirst($item, array('limite', 'limiteCartao'), 0))),
                    $this->money($this->toFloat($this->getFirst($item, array('valorReservado', 'reservado'), 0))),
                    $this->formatDate($this->getFirst($item, array('suspensaoBanco'), '')),
                    $this->formatDate($this->getFirst($item, array('suspensaoInss'), '')),
                    $this->formatDate($this->getFirst($item, array('reativacaoBanco'), '')),
                    $this->formatDate($this->getFirst($item, array('reativacaoInss'), ''))
                );
            }

            if (empty($rows)) {
                $rows[] = array('Nenhum contrato encontrado.');
            }

            return $rows;
        }

        /**
         * Cabeçalho superior das demais páginas.
         * Logo do topo removida.
         *
         * @param TCPDF $pdf
         * @return void
         */
        /**
         * ============================================================
         * BLOCO: CABEÇALHO SUPERIOR DAS PÁGINAS INTERNAS
         * ============================================================
         *
         * Usa largura dinâmica da página, mantendo o layout em horizontal.
         */
        private function drawTopHeader(TCPDF $pdf)
        {
            $pageWidth = $pdf->GetPageWidth();

            // Barra superior cinza
            $pdf->SetFillColor(62, 62, 62);
            $pdf->Rect(0, 0, $pageWidth, 26, 'F');

            // Faixa azul central
            $faixaTopoWidth = 270.16;
            $xFaixaTopo = ($pageWidth - $faixaTopoWidth) / 2;

            $pdf->SetFillColor(0, 84, 178);
            $pdf->Rect($xFaixaTopo, 0, $faixaTopoWidth, 26, 'F');

            // Texto topo
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont($this->fontArial, '', 11);

            $textoTopo = 'Instituto Nacional do Seguro Social';

            // Centralização horizontal (mantém)
            $xTextoTopo = ($pageWidth - $pdf->GetStringWidth($textoTopo)) / 2;

            // 👉 CENTRALIZAÇÃO VERTICAL REAL
            $yTextoTopo = (26 - 11) / 2 + 1;

            $pdf->Text($xTextoTopo, $yTextoTopo, $textoTopo);
        }

        /**
         * Marcador inferior.
         *
         * @param TCPDF $pdf
         * @return void
         */

        /**
         * ============================================================
         * BLOCO: MARCADOR INFERIOR
         * ============================================================
         *
         * Ajustado para página horizontal.
         */
        private function drawBottomMarker(TCPDF $pdf)
        {
            $pageWidth  = $pdf->GetPageWidth();
            $pageHeight = $pdf->GetPageHeight();

            // posição vertical do rodapé visual
            $lineY = $pageHeight - 52;

            // linha cinza
            $pdf->SetDrawColor(189, 191, 193);
            $pdf->SetLineWidth(1);
            $pdf->Line(20, $lineY, $pageWidth - 20, $lineY);

            // marcador azul central
            $markerWidth = 60;
            $markerX = ($pageWidth - $markerWidth) / 2;

            $pdf->SetFillColor(0, 84, 178);
            $pdf->Rect($markerX, $lineY - 2, $markerWidth, 4, 'F');
        }

        /**
         * Rodapé simples das demais páginas.
         *
         * @param TCPDF $pdf
         * @param string $generatedAt
         * @param string $pageLabel
         * @return void
         */

        /**
         * ============================================================
         * BLOCO: RODAPÉ DAS PÁGINAS INTERNAS
         * ============================================================
         *
         * Ajustado para página horizontal:
         * - logo no canto inferior esquerdo
         * - data no canto inferior direito
         * - paginação abaixo da data
         */
        private function drawFooter(TCPDF $pdf, $generatedAt, $pageLabel)
        {
            $pageHeight = $pdf->GetPageHeight();
            $pageWidth  = $pdf->GetPageWidth();

            // base vertical segura do rodapé
            $logoY = $pageHeight - 40;
            $textY = $pageHeight - 34;
            $pageY = $pageHeight - 20;

            // logo no canto inferior esquerdo
            if (!empty($this->assetIconInss) && file_exists($this->assetIconInss)) {
                $pdf->Image($this->assetIconInss, 28, $logoY, 95, 30, 'PNG');
            }

            // data/hora no canto inferior direito
            $pdf->SetFont($this->fontRegular, '', 8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Text($pageWidth - 110, $textY, $generatedAt);

            // paginação no canto inferior direito
            $pdf->Text($pageWidth - 45, $pageY, $pageLabel);
        }

        /**
         * Faixa cinza de seção.
         *
         * @param TCPDF $pdf
         * @param float $x
         * @param float $y
         * @param float $w
         * @param float $h
         * @param string $title
         * @return void
         */
        private function drawSectionTitle(TCPDF $pdf, $x, $y, $w, $h, $title)
        {
            // NOVA COR DE FUNDO (mais elegante)
            $pdf->SetFillColor(210, 218, 226); // cinza azulado leve

            // BORDA
            $pdf->SetDrawColor(160, 160, 160);

            $pdf->Rect($x, $y, $w, $h, 'FD');

            // NOVA COR DE TEXTO
            $pdf->SetTextColor(40, 40, 40);

            // FONTE MENOR
            $pdf->SetFont($this->fontBold, '', 9.5);

            // CENTRALIZAÇÃO REAL
            $pdf->SetXY($x, $y + (($h - 10) / 2));

            $pdf->Cell($w, 10, $title, 0, 0, 'C'); // ← CENTRALIZADO
        }

        /**
         * Tabela simples.
         *
         * @param TCPDF $pdf
         * @param float $x
         * @param float $y
         * @param array $rows
         * @param array $widths
         * @param float $rowHeight
         * @param bool $headerGray
         * @return void
         */

        /**
         * ============================================================
         * BLOCO: TABELA SIMPLES
         * ============================================================
         *
         * Ajustes:
         * - pode unificar o header em uma linha única
         * - desenha sempre baseado na quantidade de larguras
         * - evita sumiço de coluna quando a linha vem incompleta
         */
        private function drawSimpleTable(TCPDF $pdf, $x, $y, array $rows, array $widths, $rowHeight, $headerGray = false, $singleHeaderRow = false)
        {
            $currentY = $y;

            foreach ($rows as $rowIndex => $row) {
                $currentX = $x;

                // Caso especial: header único ocupando toda a largura da tabela.
                if ($rowIndex === 0 && $headerGray && $singleHeaderRow) {
                    $totalWidth = array_sum($widths);

                    $pdf->SetFillColor(233, 236, 239);
                    $pdf->Rect($currentX, $currentY, $totalWidth, $rowHeight, 'FD');

                    $this->writeCellText(
                        $pdf,
                        $currentX,
                        $currentY,
                        $totalWidth,
                        $rowHeight,
                        $this->fontBold,
                        10,
                        (string) ($row[0] ?? ''),
                        'C'
                    );

                    $currentY += $rowHeight;
                    continue;
                }

                // Desenha a linha baseada na estrutura da tabela, não no array vindo da linha.
                $totalCols = count($widths);

                for ($colIndex = 0; $colIndex < $totalCols; $colIndex++) {
                    $cell = isset($row[$colIndex]) ? $row[$colIndex] : '';
                    $width = (float) $widths[$colIndex];

                    if ($rowIndex === 0 && $headerGray) {
                        $pdf->SetFillColor(233, 236, 239);
                        $pdf->Rect($currentX, $currentY, $width, $rowHeight, 'FD');
                    } else {
                        $pdf->Rect($currentX, $currentY, $width, $rowHeight, 'D');
                    }

                    $font = ($rowIndex === 0) ? $this->fontBold : $this->fontRegular;

                    // Header normal centralizado; demais linhas alinhadas por coluna.
                    if ($rowIndex === 0) {
                        $align = 'C';
                    } else {
                        $align = ($colIndex === 0) ? 'L' : 'R';
                    }

                    $this->writeCellText(
                        $pdf,
                        $currentX,
                        $currentY,
                        $width,
                        $rowHeight,
                        $font,
                        10,
                        (string) $cell,
                        $align
                    );

                    $currentX += $width;
                }

                $currentY += $rowHeight;
            }
        }

        /**
         * Tabela grade.
         *
         * @param TCPDF $pdf
         * @param float $x
         * @param float $y
         * @param array $columns
         * @param array $rows
         * @param float $rowHeight
         * @return void
         */

        /**
         * ========================================================
         * BLOCO: TABELA EM GRADE COM CABEÇALHO AGRUPADO
         * ========================================================
         *
         * Ajustes aplicados:
         * - corrige sobreposição no cabeçalho
         * - evita Undefined array key
         * - suporta quebra de linha
         * - devolve o Y final da tabela
         * - mantém alinhamento vertical consistente
         */
        private function drawGridTable(
            TCPDF $pdf,
            $x,
            $y,
            array $columns,
            array $rows,
            $rowHeight = 34,
            $cellPaddingX = 2,
            $cellPaddingY = 1
        ) {
            $startX = $x;
            $currentY = $y;

            $pdf->SetDrawColor(120, 120, 120);
            $pdf->SetLineWidth(0.6);

            // Salva o padding atual e aplica o padding específico desta tabela.
            $previousCellPadding = $pdf->getCellPaddings();
            $pdf->setCellPaddings($cellPaddingX, $cellPaddingY, $cellPaddingX, $cellPaddingY);

            /**
             * ========================================================
             * BLOCO: CABEÇALHO AGRUPADO (2 NÍVEIS)
             * ========================================================
             */
            $topHeaderH = 18;
            $subHeaderH = 22;
            $fullHeaderH = $topHeaderH + $subHeaderH;

            $pdf->SetFont($this->fontBold, '', 4.8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFillColor(225, 228, 232);

            // Larguras por índice.
            $w = array();
            foreach ($columns as $i => $col) {
                $w[$i] = isset($col['width']) ? (float) $col['width'] : 40.0;
            }

            /**
             * ========================================================
             * BLOCO: TABELA DE EMPRÉSTIMOS (22 COLUNAS)
             * ========================================================
             */
            if (count($columns) === 22) {
                // Primeiras 5 colunas ocupam as 2 linhas do cabeçalho.
                $rowspanColumns = array(0, 1, 2, 3, 4);

                $headerX = $startX;
                for ($i = 0; $i <= 4; $i++) {
                    $pdf->Rect($headerX, $currentY, $w[$i], $fullHeaderH, 'DF');
                    $pdf->MultiCell(
                        $w[$i],
                        $fullHeaderH,
                        isset($columns[$i]['label']) ? $columns[$i]['label'] : '',
                        0,
                        'C',
                        false,
                        0,
                        $headerX,
                        $currentY,
                        true,
                        0,
                        false,
                        true,
                        $fullHeaderH,
                        'M'
                    );
                    $headerX += $w[$i];
                }

                // Grupo "DATA" (colunas 5, 6, 7).
                $groupDataWidth = $w[5] + $w[6] + $w[7];
                $pdf->Rect($headerX, $currentY, $groupDataWidth, $topHeaderH, 'DF');
                $pdf->MultiCell(
                    $groupDataWidth,
                    $topHeaderH,
                    'DATA',
                    0,
                    'C',
                    false,
                    0,
                    $headerX,
                    $currentY,
                    true,
                    0,
                    false,
                    true,
                    $topHeaderH,
                    'M'
                );

                $subX = $headerX;
                for ($i = 5; $i <= 7; $i++) {
                    $pdf->Rect($subX, $currentY + $topHeaderH, $w[$i], $subHeaderH, 'DF');
                    $pdf->MultiCell(
                        $w[$i],
                        $subHeaderH,
                        isset($columns[$i]['label']) ? $columns[$i]['label'] : '',
                        0,
                        'C',
                        false,
                        0,
                        $subX,
                        $currentY + $topHeaderH,
                        true,
                        0,
                        false,
                        true,
                        $subHeaderH,
                        'M'
                    );
                    $subX += $w[$i];
                }
                $headerX += $groupDataWidth;

                // Colunas 8 até 16 ocupam as 2 linhas.
                for ($i = 8; $i <= 16; $i++) {
                    $pdf->Rect($headerX, $currentY, $w[$i], $fullHeaderH, 'DF');
                    $pdf->MultiCell(
                        $w[$i],
                        $fullHeaderH,
                        isset($columns[$i]['label']) ? $columns[$i]['label'] : '',
                        0,
                        'C',
                        false,
                        0,
                        $headerX,
                        $currentY,
                        true,
                        0,
                        false,
                        true,
                        $fullHeaderH,
                        'M'
                    );
                    $headerX += $w[$i];
                }

                // Grupo "CET" (17, 18).
                $groupCetWidth = $w[17] + $w[18];
                $pdf->Rect($headerX, $currentY, $groupCetWidth, $topHeaderH, 'DF');
                $pdf->MultiCell(
                    $groupCetWidth,
                    $topHeaderH,
                    'CET',
                    0,
                    'C',
                    false,
                    0,
                    $headerX,
                    $currentY,
                    true,
                    0,
                    false,
                    true,
                    $topHeaderH,
                    'M'
                );

                $subX = $headerX;
                for ($i = 17; $i <= 18; $i++) {
                    $pdf->Rect($subX, $currentY + $topHeaderH, $w[$i], $subHeaderH, 'DF');
                    $pdf->MultiCell(
                        $w[$i],
                        $subHeaderH,
                        isset($columns[$i]['label']) ? $columns[$i]['label'] : '',
                        0,
                        'C',
                        false,
                        0,
                        $subX,
                        $currentY + $topHeaderH,
                        true,
                        0,
                        false,
                        true,
                        $subHeaderH,
                        'M'
                    );
                    $subX += $w[$i];
                }
                $headerX += $groupCetWidth;

                // Grupo "TX" (19, 20).
                $groupTxWidth = $w[19] + $w[20];
                $pdf->Rect($headerX, $currentY, $groupTxWidth, $topHeaderH, 'DF');
                $pdf->MultiCell(
                    $groupTxWidth,
                    $topHeaderH,
                    'TX',
                    0,
                    'C',
                    false,
                    0,
                    $headerX,
                    $currentY,
                    true,
                    0,
                    false,
                    true,
                    $topHeaderH,
                    'M'
                );

                $subX = $headerX;
                for ($i = 19; $i <= 20; $i++) {
                    $pdf->Rect($subX, $currentY + $topHeaderH, $w[$i], $subHeaderH, 'DF');
                    $pdf->MultiCell(
                        $w[$i],
                        $subHeaderH,
                        isset($columns[$i]['label']) ? $columns[$i]['label'] : '',
                        0,
                        'C',
                        false,
                        0,
                        $subX,
                        $currentY + $topHeaderH,
                        true,
                        0,
                        false,
                        true,
                        $subHeaderH,
                        'M'
                    );
                    $subX += $w[$i];
                }
                $headerX += $groupTxWidth;

                // Última coluna (21) ocupa as 2 linhas.
                $pdf->Rect($headerX, $currentY, $w[21], $fullHeaderH, 'DF');
                $pdf->MultiCell(
                    $w[21],
                    $fullHeaderH,
                    isset($columns[21]['label']) ? $columns[21]['label'] : '',
                    0,
                    'C',
                    false,
                    0,
                    $headerX,
                    $currentY,
                    true,
                    0,
                    false,
                    true,
                    $fullHeaderH,
                    'M'
                );

                $currentY += $fullHeaderH;
            } else {
                /**
                 * ========================================================
                 * BLOCO: CABEÇALHO SIMPLES
                 * ========================================================
                 */
                $headerHeight = max(24, $rowHeight);

                $headerX = $startX;
                foreach ($columns as $index => $column) {
                    $width = isset($column['width']) ? (float) $column['width'] : 40.0;
                    $label = isset($column['label']) ? (string) $column['label'] : '';

                    $pdf->Rect($headerX, $currentY, $width, $headerHeight, 'DF');
                    $pdf->MultiCell(
                        $width,
                        $headerHeight,
                        $label,
                        0,
                        'C',
                        false,
                        0,
                        $headerX,
                        $currentY,
                        true,
                        0,
                        false,
                        true,
                        $headerHeight,
                        'M'
                    );

                    $headerX += $width;
                }

                $currentY += $headerHeight;
            }

            /**
             * ========================================================
             * BLOCO: LINHAS DE DADOS
             * ========================================================
             */
            $pdf->SetFont($this->fontRegular, '', 6.8);

            foreach ($rows as $row) {
                $currentX = $startX;
                $totalCols = count($columns);

                for ($colIndex = 0; $colIndex < $totalCols; $colIndex++) {
                    $cellText = isset($row[$colIndex]) ? (string) $row[$colIndex] : '';
                    $width = isset($columns[$colIndex]['width']) ? (float) $columns[$colIndex]['width'] : 40.0;
                    $align = isset($columns[$colIndex]['align']) ? (string) $columns[$colIndex]['align'] : 'C';

                    $pdf->Rect($currentX, $currentY, $width, $rowHeight, 'D');
                    $pdf->MultiCell(
                        $width,
                        $rowHeight,
                        $cellText,
                        0,
                        $align,
                        false,
                        0,
                        $currentX,
                        $currentY,
                        true,
                        0,
                        false,
                        true,
                        $rowHeight,
                        'M'
                    );

                    $currentX += $width;
                }

                $currentY += $rowHeight;
            }

            // Restaura o padding anterior para não impactar outras tabelas.
            $pdf->setCellPaddings(
                $previousCellPadding['L'],
                $previousCellPadding['T'],
                $previousCellPadding['R'],
                $previousCellPadding['B']
            );

            return $currentY;
        }

        /**
         * Texto em célula.
         *
         * @param TCPDF $pdf
         * @param float $x
         * @param float $y
         * @param float $w
         * @param float $h
         * @param string $font
         * @param int $size
         * @param string $text
         * @return void
         */
        private function writeCellText(TCPDF $pdf, $x, $y, $w, $h, $font, $fontSize, $text, $align = 'L')
        {
            $pdf->SetFont($font, '', $fontSize);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $h, $text, 0, 0, $align, false, '', 1, false, 'T', 'M');
        }

        /**
         * Texto simples.
         *
         * @param TCPDF $pdf
         * @param float $x
         * @param float $y
         * @param string $font
         * @param int $size
         * @param string $text
         * @return void
         */
        private function writeText(TCPDF $pdf, $x, $y, $font, $size, $text)
        {
            $pdf->SetFont($font, '', $size);
            $pdf->Text($x, $y, $text);
        }

        /**
         * Texto com quebra.
         *
         * @param TCPDF $pdf
         * @param float $x
         * @param float $y
         * @param float $w
         * @param float $h
         * @param string $font
         * @param int $size
         * @param string $text
         * @return void
         */
        private function writeWrappedText(TCPDF $pdf, $x, $y, $w, $h, $font, $size, $text)
        {
            $pdf->SetFont($font, '', $size);
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($w, $h, $text, 0, 'L', false, 1);
        }

        /**
         * Formata moeda.
         *
         * @param float $value
         * @return string
         */
        private function money($value)
        {
            return 'R$' . number_format((float) $value, 2, ',', '.');
        }

        /**
         * Converte em float.
         *
         * @param mixed $value
         * @return float
         */
        private function toFloat($value)
        {
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }

            if (!is_string($value)) {
                return 0.0;
            }

            $normalized = trim($value);
            if ($normalized === '') {
                return 0.0;
            }

            $normalized = str_replace(array('R$', ' '), '', $normalized);
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);

            return is_numeric($normalized) ? (float) $normalized : 0.0;
        }

        /**
         * d/m/Y
         *
         * @param mixed $value
         * @return string
         */
        private function formatDate($value)
        {
            if (empty($value)) {
                return '';
            }

            $timestamp = strtotime((string) $value);
            return $timestamp ? date_i18n('d/m/Y', $timestamp) : $this->safeText((string) $value);
        }

        /**
         * m/Y
         *
         * @param mixed $value
         * @return string
         */
        private function formatMonthYear($value)
        {
            if (empty($value)) {
                return '';
            }

            if (is_string($value) && preg_match('/^\d{2}\/\d{4}$/', $value)) {
                return $value;
            }

            $timestamp = strtotime((string) $value);
            return $timestamp ? date_i18n('m/Y', $timestamp) : $this->safeText((string) $value);
        }

        /**
         * d/m/Y H:i:s
         *
         * @param mixed $value
         * @return string
         */
        private function formatDateTime($value)
        {
            $timestamp = strtotime((string) $value);
            return $timestamp ? date_i18n('d/m/Y H:i:s', $timestamp) : date_i18n('d/m/Y H:i:s');
        }

        /**
         * Conta por status.
         *
         * @param array $items
         * @param string $status
         * @return int
         */
        private function countByStatus(array $items, $status)
        {
            $count = 0;
            $wanted = mb_strtolower(trim($status));

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $current = mb_strtolower(trim((string) $this->getFirst($item, array('situacao'), '')));
                if ($current === $wanted) {
                    $count++;
                }
            }

            return $count;
        }

        /**
         * Soma campo monetário.
         *
         * @param array $items
         * @param array $keys
         * @return float
         */
        private function sumField(array $items, array $keys)
        {
            $sum = 0.0;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $sum += $this->toFloat($this->getFirst($item, $keys, 0));
            }

            return $sum;
        }

        /**
         * Descrição do banco.
         *
         * @param mixed $bank
         * @return string
         */
        private function bankDescription($bank)
        {
            if (is_array($bank)) {
                $descricao = $this->getFirst($bank, array('descricao', 'nome'), '');
                $codigo = $this->getFirst($bank, array('codigo', 'code'), '');

                if ($descricao !== '') {
                    return $this->safeText($descricao);
                }

                if ($codigo !== '') {
                    return $this->safeText($codigo);
                }

                return '—';
            }

            return $this->safeText((string) $bank);
        }

        /**
         * Extrai código do banco.
         *
         * @param mixed $bank
         * @return string
         */
        private function extractBankCode($bank)
        {
            $text = $this->bankDescription($bank);
            if (preg_match('/^\s*(\d{3})\b/', $text, $m)) {
                return $m[1];
            }

            return $text;
        }

        /**
         * Formata número do benefício.
         *
         * @param mixed $value
         * @return string
         */
        private function formatBenefitNumber($value)
        {
            $raw = trim((string) $value);
            $digits = preg_replace('/\D+/', '', $raw);

            if ($digits === '') {
                return '—';
            }

            if (strpos($raw, '*') !== false) {
                return '—';
            }

            if (strlen($digits) < 10) {
                $digits = str_pad($digits, 10, '0', STR_PAD_LEFT);
            }

            if (strlen($digits) !== 10) {
                return '—';
            }

            return substr($digits, 0, 3) . '.'
                . substr($digits, 3, 3) . '.'
                . substr($digits, 6, 3) . '-'
                . substr($digits, 9, 1);
        }

        /**
         * Tenta achar NB dentro dos contratos.
         *
         * @param array $emprestimos
         * @param array $rmc
         * @param array $rcc
         * @return string
         */
        private function extractBenefitNumberFromContracts(array $emprestimos, array $rmc, array $rcc)
        {
            $groups = array($emprestimos, $rmc, $rcc);

            foreach ($groups as $items) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $nb = $this->getFirst($item, array(
                        'beneficio',
                        'numeroBeneficio',
                        'nb',
                    ), '');

                    $nb = trim((string) $nb);
                    if ($nb !== '') {
                        return $nb;
                    }
                }
            }

            return '';
        }

        /**
         * Texto booleano.
         *
         * @param mixed $value
         * @param string $trueText
         * @param string $falseText
         * @return string
         */
        private function booleanDescription($value, $trueText, $falseText)
        {
            return $this->isTruthy($value) ? $trueText : $falseText;
        }

        /**
         * Truthy helper.
         *
         * @param mixed $value
         * @return bool
         */
        private function isTruthy($value)
        {
            if ($value === true || $value === 1 || $value === '1') {
                return true;
            }

            if (is_string($value)) {
                $normalized = mb_strtolower(trim($value));
                return in_array($normalized, array('sim', 's', 'true', 'yes', 'y'), true);
            }

            return false;
        }

        /**
         * Texto seguro.
         *
         * @param mixed $value
         * @return string
         */
        private function safeText($value)
        {
            if (is_array($value) || is_object($value)) {
                return '—';
            }

            $value = trim((string) $value);
            return $value !== '' ? $value : '—';
        }

        /**
         * Normaliza array.
         *
         * @param mixed $value
         * @return array
         */
        private function normalizeArray($value)
        {
            return is_array($value) ? $value : array();
        }

        /**
         * Busca primeiro valor existente.
         *
         * @param array $source
         * @param array $keys
         * @param mixed $default
         * @return mixed
         */
        private function getFirst(array $source, array $keys, $default = null)
        {
            foreach ($keys as $key) {
                $value = $this->getByPath($source, (string) $key, null);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return $default;
        }

        /**
         * Busca por path com notação ponto.
         *
         * @param array $source
         * @param string $path
         * @param mixed $default
         * @return mixed
         */
        private function getByPath(array $source, $path, $default = null)
        {
            if ($path === '') {
                return $default;
            }

            if (array_key_exists($path, $source)) {
                return $source[$path];
            }

            $segments = explode('.', $path);
            $current = $source;

            foreach ($segments as $segment) {
                if (!is_array($current) || !array_key_exists($segment, $current)) {
                    return $default;
                }

                $current = $current[$segment];
            }

            return $current;
        }
    }
}
