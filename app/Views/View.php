<?php

declare(strict_types=1);

namespace Acme\AccountControl\Views;

/**
 * Renderer simples de Views do ACME.
 *
 * Objetivo:
 * Centralizar a renderização de templates PHP sem introduzir engines externas
 * como Twig ou Blade. Isso mantém o plugin simples, compatível com WordPress e
 * fácil de debugar por qualquer pessoa familiarizada com PHP tradicional.
 *
 * Motivo da implementação:
 * O plugin legado possui HTML misturado com callbacks, shortcodes e funções
 * globais. Esta classe cria um ponto único para mover telas gradualmente para
 * `app/Views`, sem quebrar templates antigos que ainda vivem em `includes/views`.
 *
 * Riscos e cuidados:
 * - `extract()` é usado de forma controlada apenas com dados fornecidos pelo
 *   Controller. Controllers devem sanitizar/escapar dados sensíveis antes de
 *   renderizar ou garantir que a View escape a saída com funções do WordPress.
 * - A resolução de caminho bloqueia traversal (`..`) para evitar inclusão de
 *   arquivos fora da área prevista do plugin.
 */
final class View
{
    /** @var string */
    private $pluginPath;

    public function __construct(string $pluginPath)
    {
        $this->pluginPath = rtrim($pluginPath, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Renderiza uma View nova localizada em `app/Views`.
     *
     * O nome usa notação por ponto para facilitar leitura:
     * `admin.dashboard.index` vira `app/Views/admin/dashboard/index.php`.
     *
     * @param array<string,mixed> $data Dados disponibilizados para o template.
     */
    public function render(string $viewName, array $data = []): void
    {
        $viewFile = $this->resolveAppViewPath($viewName);
        $this->includeFile($viewFile, $data);
    }

    /**
     * Renderiza e retorna o HTML de uma View nova.
     *
     * Esse método é útil para shortcodes, porque shortcodes devem retornar a
     * string renderizada em vez de imprimir conteúdo diretamente na página.
     *
     * @param array<string,mixed> $data Dados disponibilizados para o template.
     */
    public function capture(string $viewName, array $data = []): string
    {
        ob_start();
        $this->render($viewName, $data);
        return (string) ob_get_clean();
    }

    /**
     * Renderiza e retorna HTML de uma View legada ainda mantida em `includes`.
     *
     * Motivo:
     * Durante a refatoração incremental, alguns templates grandes continuam no
     * local antigo. Esta ponte permite que Controllers novos usem esses arquivos
     * sem duplicar HTML e sem alterar o comportamento visual atual.
     *
     * @param array<string,mixed> $data Dados disponibilizados para o template.
     */
    public function captureLegacy(string $relativePath, array $data = []): string
    {
        ob_start();
        $this->renderLegacy($relativePath, $data);
        return (string) ob_get_clean();
    }

    /**
     * Renderiza uma View legada imprimindo o conteúdo imediatamente.
     *
     * @param array<string,mixed> $data Dados disponibilizados para o template.
     */
    public function renderLegacy(string $relativePath, array $data = []): void
    {
        $hookFile = $this->resolveLegacyPath($relativePath);
        $this->includeFile($hookFile, $data);
    }

    /**
     * Resolve caminho de uma View nova dentro de `app/Views`.
     */
    private function resolveAppViewPath(string $viewName): string
    {
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $viewName) . '.php';
        return $this->assertSafePath($this->pluginPath . 'app/Views/' . $relativePath);
    }

    /**
     * Resolve caminho de uma View legada sem permitir saída do diretório do plugin.
     */
    private function resolveLegacyPath(string $relativePath): string
    {
        $normalizedPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        return $this->assertSafePath($this->pluginPath . $normalizedPath);
    }

    /**
     * Garante que o arquivo exista e esteja dentro do diretório do plugin.
     *
     * Segurança:
     * Usamos `realpath()` em vez de apenas procurar `..` na string. Isso evita
     * traversal por caminhos normalizados, links simbólicos e variações de barra
     * entre sistemas operacionais. Como Views podem receber nomes vindos de
     * Controllers, esta checagem protege contra inclusão acidental de arquivos
     * fora do plugin.
     */
    private function assertSafePath(string $filePath): string
    {
        $realPluginPath = realpath($this->pluginPath);
        $realFilePath = realpath($filePath);

        if ($realPluginPath === false || $realFilePath === false || !is_file($realFilePath)) {
            throw new \RuntimeException('View não encontrada: ' . $filePath);
        }

        $pluginPrefix = rtrim($realPluginPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (strpos($realFilePath, $pluginPrefix) !== 0) {
            throw new \RuntimeException('Caminho de View inválido.');
        }

        return $realFilePath;
    }

    /**
     * Inclui o arquivo em escopo isolado com variáveis controladas.
     *
     * @param array<string,mixed> $data Dados disponibilizados para o template.
     */
    private function includeFile(string $filePath, array $data): void
    {
        // As variáveis são extraídas apenas aqui para manter os Controllers
        // livres de HTML e evitar includes espalhados pelo plugin.
        extract($data, EXTR_SKIP);
        include $filePath;
    }
}
