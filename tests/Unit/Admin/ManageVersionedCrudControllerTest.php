<?php

declare(strict_types=1);

namespace App\Managing\Tests\Unit\Admin;

use App\Managing\Controller\Crud\Generated\ManageAttachingCrudController;
use App\Managing\Controller\Crud\Generated\ManagePagingCrudController;
use App\Managing\Generator\Admin\ManageCrudControllerGenerator;
use App\Managing\Value\ManageCrudResourceDefinition;
use App\Managing\Writer\Admin\ManageGeneratedCrudControllerWriter;
use PHPUnit\Framework\TestCase;

final class ManageVersionedCrudControllerTest extends TestCase
{
    public function testSelectionDoesNotCreateOrRemoveControllerSource(): void
    {
        $root = sys_get_temp_dir().'/manage-source-'.bin2hex(random_bytes(12));
        $generator = new ManageCrudControllerGenerator($root);
        $paging = new ManageCrudResourceDefinition('paging', 'page', 'Page', 'App\Paging\Entity\Page');
        $disabled = new ManageCrudResourceDefinition('shipping', 'shipment', 'Shipment', 'App\Shipping\Entity\Shipping\ShipmentEntity', enabled: false);

        self::assertSame([ManagePagingCrudController::class => $paging], $generator->selectPrimaryControllers([$paging, $disabled]));
        self::assertFileDoesNotExist($root);
    }

    public function testWriterDoesNotOverwriteExistingSourceOrDeleteOtherControllers(): void
    {
        $root = sys_get_temp_dir().'/manage-source-'.bin2hex(random_bytes(12));
        $writer = new ManageGeneratedCrudControllerWriter($root);
        $dir = $writer->generatedDirectory();
        mkdir($dir, 0700, true);
        $path = $dir.'/ManagePagingCrudController.php';
        $source = '<?php // maintained source';
        file_put_contents($path, $source);
        try {
            try {
                $writer->writeController(ManagePagingCrudController::class, 'App\Paging\Entity\Page', 'paging');
                self::fail('Existing source must not be overwritten.');
            } catch (\LogicException) {
                self::assertSame($source, file_get_contents($path));
            }
            $generator = new ManageCrudControllerGenerator($root);
            self::assertSame([], $generator->synchronize([]));
            self::assertSame($source, file_get_contents($path));
        } finally {
            unlink($path);
            rmdir($dir);
            rmdir(dirname($dir));
            rmdir(dirname($dir, 2));
            rmdir(dirname($dir, 3));
            rmdir($root);
        }
    }

    public function testRestoredControllerContractsPreserveAttachmentHookAndCurrentPagingIdentity(): void
    {
        self::assertSame('App\Attaching\Entity\Attachment\AttachmentEntity', ManageAttachingCrudController::getEntityFqcn());
        self::assertSame(ManageAttachingCrudController::class, (new \ReflectionMethod(ManageAttachingCrudController::class, 'index'))->getDeclaringClass()->getName());
        self::assertSame('App\Paging\Entity\Page', ManagePagingCrudController::getEntityFqcn());
    }
}
