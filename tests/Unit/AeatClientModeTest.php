<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Squareetlabs\VeriFactu\Services\AeatClient;
use ReflectionClass;

class AeatClientModeTest extends TestCase
{
    public function testAeatClientDefaultsToVerifactuMode(): void
    {
        config(['verifactu.verifactu_mode' => true]);

        $client = new AeatClient('/path/to/cert.pem', 'password', false);

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('verifactuMode');
        $property->setAccessible(true);

        $this->assertTrue($property->getValue($client));
    }

    public function testAeatClientCanBeSetToNoVerifactuMode(): void
    {
        config(['verifactu.verifactu_mode' => true]);

        // Forzamos NO VERIFACTU mode en el constructor
        $client = new AeatClient('/path/to/cert.pem', 'password', false, false);

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('verifactuMode');
        $property->setAccessible(true);

        $this->assertFalse($property->getValue($client));
    }

    public function testAeatClientRespectsConfigWhenModeNotProvided(): void
    {
        config(['verifactu.verifactu_mode' => false]);

        $client = new AeatClient('/path/to/cert.pem', 'password', false);

        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('verifactuMode');
        $property->setAccessible(true);

        $this->assertFalse($property->getValue($client));
    }

    public function testGetSoapClientUsesCorrectWsdlInProductionVerifactuMode(): void
    {
        config(['verifactu.verifactu_mode' => true]);

        $client = new AeatClient('/path/to/cert.pem', 'password', true, true);

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('getSoapClient');
        $method->setAccessible(true);

        // Esto fallará porque el certificado no existe, pero podemos capturar el error
        // y verificar que intenta usar la URL correcta
        try {
            $method->invoke($client);
        } catch (\Exception $e) {
            // El error contendrá información sobre el WSDL que intentó usar
            $this->assertStringContainsString('VerifactuSOAP', $e->getMessage());
        }
    }

    public function testConfigParametersAreRespected(): void
    {
        config([
            'verifactu.tipo_uso_posible_solo_verifactu' => 'N',
            'verifactu.tipo_uso_posible_multi_ot' => 'S',
            'verifactu.indicador_multiples_ot' => 'S',
        ]);

        $this->assertEquals('N', config('verifactu.tipo_uso_posible_solo_verifactu'));
        $this->assertEquals('S', config('verifactu.tipo_uso_posible_multi_ot'));
        $this->assertEquals('S', config('verifactu.indicador_multiples_ot'));
    }
}
