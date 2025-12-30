<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Squareetlabs\VeriFactu\Providers\VeriFactuServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Configuración mínima para pruebas, por ejemplo:
        $app['config']->set('verifactu.issuer', [
            'name' => 'Test Issuer',
            'vat' => 'A00000000',
        ]);
        // Puedes añadir más configuración si es necesario
        
        // Cargar migraciones para tests
        $app['config']->set('verifactu.load_migrations', true);
    }
} 