<?php

declare(strict_types=1);

namespace App\Managing\Writer\Admin;

use App\Managing\Extractor\Admin\ManageGeneratedCrudControllerCustomizationExtractor;
use App\Managing\Policy\Admin\ManageCrudResourcePolicy;
use App\Managing\Renderer\Admin\ManageGeneratedCrudControllerSourceRenderer;

/**
 * Owns deterministic PHP source generation for thin EasyAdmin CRUD controllers.
 *
 * ManageCrudControllerGenerator selects the primary resource for a component;
 * this writer is the only class that knows how that selection is rendered as a
 * generated controller file.
 */
final class ManageGeneratedCrudControllerWriter
{
    private ManageGeneratedCrudControllerSourceRenderer $sourceRenderer;

    private ManageGeneratedCrudControllerCustomizationExtractor $customizationExtractor;

    public function __construct(
        private readonly string $bundleDir,
        private readonly ManageCrudResourcePolicy $resourcePolicy = new ManageCrudResourcePolicy(),
        ?ManageGeneratedCrudControllerSourceRenderer $sourceRenderer = null,
        ?ManageGeneratedCrudControllerCustomizationExtractor $customizationExtractor = null,
    ) {
        $this->sourceRenderer = $sourceRenderer ?? new ManageGeneratedCrudControllerSourceRenderer($this->resourcePolicy);
        $this->customizationExtractor = $customizationExtractor ?? new ManageGeneratedCrudControllerCustomizationExtractor();
    }

    public function generatedDirectory(): string
    {
        return $this->bundleDir.'/src/Controller/Crud/Generated';
    }

    public function writeController(string $fqcn, string $entityClass, string $componentKey): void
    {
        $generatedDir = $this->generatedDirectory();
        if (!is_dir($generatedDir) && !mkdir($generatedDir, 0777, true) && !is_dir($generatedDir)) {
            throw new \RuntimeException('Cannot create the generated controller directory.');
        }

        $filePath = $generatedDir.'/'.$this->shortClassName($fqcn).'.php';

        // Shared source cannot be replaced by a different host's selected entity
        // or generation policy. An intentional source change needs explicit admission.
        if (is_file($filePath)) {
            $this->assertExistingController($filePath, $fqcn, $entityClass, $componentKey);

            return;
        }

        $content = $this->controllerSource($fqcn, $entityClass, $componentKey);
        $temporary = tempnam($generatedDir, '.managing-');
        if (false === $temporary) {
            throw new \RuntimeException('Cannot allocate generated controller temporary file: '.$fqcn);
        }

        try {
            if (realpath(dirname($temporary)) !== realpath($generatedDir)) {
                throw new \RuntimeException('Generated controller temporary file is outside its source directory.');
            }
            if (strlen($content) !== file_put_contents($temporary, $content, LOCK_EX)) {
                throw new \RuntimeException('Cannot write generated controller source: '.$fqcn);
            }
            if (!chmod($temporary, 0666 & ~umask())) {
                throw new \RuntimeException('Cannot set generated controller source permissions: '.$fqcn);
            }

            // Publishing by hard link is atomic and cannot overwrite an existing
            // file, even when another process has selected the same FQCN.
            if (!@link($temporary, $filePath)) {
                clearstatcache(true, $filePath);
                $this->assertExistingController($filePath, $fqcn, $entityClass, $componentKey);
            }
        } finally {
            if (!unlink($temporary)) {
                throw new \RuntimeException('Cannot remove generated controller temporary file: '.$fqcn);
            }
        }
    }

    private function assertExistingController(string $filePath, string $fqcn, string $entityClass, string $componentKey): void
    {
        $existingSource = is_file($filePath) ? file_get_contents($filePath) : false;
        if (false === $existingSource || $existingSource !== $this->controllerSource($fqcn, $entityClass, $componentKey, $existingSource)) {
            throw new \RuntimeException('Generated controller source conflict; existing source preserved: '.$fqcn);
        }
    }

    public function controllerSource(string $fqcn, string $entityClass, string $componentKey, string $existingSource = ''): string
    {
        return $this->sourceRenderer->render(
            $fqcn,
            $entityClass,
            $componentKey,
            $this->customizationExtractor->extractCustomMethodsBlock($existingSource),
        );
    }

    private function shortClassName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return false === $position ? $fqcn : substr($fqcn, $position + 1);
    }
}
