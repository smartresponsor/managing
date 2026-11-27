<?php

declare(strict_types=1);

namespace App\Managing\Tests\Unit\Admin;

use App\Managing\Policy\Admin\ManageCrudResourcePolicy;
use App\Managing\Writer\Admin\ManageGeneratedCrudControllerWriter;
use PHPUnit\Framework\TestCase;

final class ManageGeneratedCrudControllerWriterTest extends TestCase
{
    public function testSynchronizationPreservesControllersOutsideTheCurrentHostSelection(): void
    {
        $directory = sys_get_temp_dir().'/manage-preservation-'.bin2hex(random_bytes(8));
        $writer = new ManageGeneratedCrudControllerWriter($directory);
        $generator = new \App\Managing\Generator\Admin\ManageCrudControllerGenerator($directory, writer: $writer);
        $generatedDirectory = $writer->generatedDirectory();
        mkdir($generatedDirectory, 0700, true);
        $existingFile = $generatedDirectory.'/ManageShippingCrudController.php';
        $existingSource = '<?php // Preserved controller customization.';
        file_put_contents($existingFile, $existingSource);

        try {
            self::assertSame([], $generator->synchronize([]));
            self::assertSame($existingSource, file_get_contents($existingFile));
        } finally {
            unlink($existingFile);
            rmdir($generatedDirectory);
            rmdir(dirname($generatedDirectory));
            rmdir(dirname($generatedDirectory, 2));
            rmdir(dirname($generatedDirectory, 3));
            rmdir($directory);
        }
    }

    public function testConflictingHostSelectionCannotReplaceExistingControllerSource(): void
    {
        $directory = sys_get_temp_dir().'/manage-conflict-'.bin2hex(random_bytes(8));
        $writer = new ManageGeneratedCrudControllerWriter($directory);
        $fqcn = 'App\\Managing\\Controller\\Crud\\Generated\\ManageShippingCrudController';
        $file = $writer->generatedDirectory().'/ManageShippingCrudController.php';

        try {
            $writer->writeController($fqcn, 'App\\Shipping\\Entity\\ShipmentEntity', 'shipping');
            $source = file_get_contents($file);
            self::assertIsString($source);
            $writer->writeController($fqcn, 'App\\Shipping\\Entity\\ShipmentEntity', 'shipping');
            self::assertSame($source, file_get_contents($file));

            try {
                $writer->writeController($fqcn, 'App\\Shipping\\Entity\\ParcelEntity', 'shipping');
                self::fail('A conflicting host selection must not replace existing controller source.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('existing source preserved', $exception->getMessage());
                self::assertSame($source, file_get_contents($file));
            }
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            foreach ([$writer->generatedDirectory(), dirname($writer->generatedDirectory()), dirname($writer->generatedDirectory(), 2), dirname($writer->generatedDirectory(), 3), $directory] as $path) {
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
        }
    }

    public function testItRendersReadOnlyControllerWithoutMigrationTrait(): void
    {
        $writer = new ManageGeneratedCrudControllerWriter(sys_get_temp_dir(), new ManageCrudResourcePolicy());

        $source = $writer->controllerSource(
            'App\\Managing\\Controller\\Crud\\Generated\\ReportingCrudController',
            'App\\Reporting\\Entity\\ReportView',
            'reporting',
        );

        self::assertStringContainsString('final class ReportingCrudController', $source);
        self::assertStringContainsString('return \\App\\Reporting\\Entity\\ReportView::class;', $source);
        self::assertStringContainsString('protected static function manageIsReadOnly(): bool', $source);
        self::assertStringNotContainsString('ManageAttachmentIdentifierMigrationTrait', $source);
    }

    public function testItRendersAttachmentMigrationBridgeOnlyForConfiguredComponent(): void
    {
        $writer = new ManageGeneratedCrudControllerWriter(
            sys_get_temp_dir(),
            new ManageCrudResourcePolicy(componentsRequiringAttachmentIdentifierMigration: ['attaching']),
        );

        $source = $writer->controllerSource(
            'App\\Managing\\Controller\\Crud\\Generated\\ManageAttachingCrudController',
            'App\\Attaching\\Entity\\Attachment\\Attachment',
            'attaching',
        );

        self::assertStringContainsString('use App\\Managing\\Trait\\Crud\\ManageAttachmentIdentifierMigrationTrait;', $source);
        self::assertStringContainsString('use ManageAttachmentIdentifierMigrationTrait;', $source);
        self::assertStringContainsString('$this->migrateAttachmentIdentifierIfNeeded();', $source);
        self::assertStringNotContainsString('new \\App\\Managing\\Service\\Admin\\AttachmentIdentifierMigrationService', $source);
    }

    public function testItPreservesGeneratedControllerCustomizationHooksDuringRewrite(): void
    {
        $writer = new ManageGeneratedCrudControllerWriter(sys_get_temp_dir(), new ManageCrudResourcePolicy());
        $existingSource = <<<'PHP'
<?php

final class ApplicatingCrudController
{
    public static function getEntityFqcn(): string
    {
        return OldEntity::class;
    }

    /** @return array<string, array<string, string>> */
    protected static function manageArrayChoiceFields(): array
    {
        return [
            'roles' => [
                'Application admin' => 'ROLE_APPLICATION_ADMIN',
            ],
        ];
    }
}
PHP;

        $source = $writer->controllerSource(
            'App\\Managing\\Controller\\Crud\\Generated\\ApplicatingCrudController',
            'App\\Applicating\\Entity\\ApplicationUser',
            'applicating',
            $existingSource,
        );

        self::assertStringContainsString('return \\App\\Applicating\\Entity\\ApplicationUser::class;', $source);
        self::assertStringContainsString('protected static function manageArrayChoiceFields(): array', $source);
        self::assertStringContainsString('ROLE_APPLICATION_ADMIN', $source);
    }
}
