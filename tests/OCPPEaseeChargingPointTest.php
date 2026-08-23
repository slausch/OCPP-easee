<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

include_once __DIR__ . '/stubs/autoload.php';

if (!defined('VARIABLE_PRESENTATION_VALUE_PRESENTATION')) {
    define('VARIABLE_PRESENTATION_VALUE_PRESENTATION', 'ValuePresentation');
    define('VARIABLE_PRESENTATION_SLIDER', 'Slider');
    define('VARIABLE_PRESENTATION_SWITCH', 'Switch');
}

class OCPPEaseeChargingPointTest extends TestCase
{
    private OCPPEaseeChargingPoint $instance;

    protected function setUp(): void
    {
        include_once __DIR__ . '/../OCPP Charging Point/module.php';
        $this->instance = new OCPPEaseeChargingPoint(1);

        $properties = new ReflectionProperty(IPSModule::class, 'properties');
        $properties->setValue($this->instance, [
            'MaximumChargingCurrent'     => ['Type' => 2, 'Current' => 32.0, 'Pending' => 32.0],
            'ChargingProfileId'          => ['Type' => 1, 'Current' => 1000, 'Pending' => 1000],
            'ChargingProfileStackLevel'  => ['Type' => 1, 'Current' => 0, 'Pending' => 0],
            'DefaultConnectorId'         => ['Type' => 1, 'Current' => 1, 'Pending' => 1],
            'ChargePointIdentity'        => ['Type' => 3, 'Current' => 'test', 'Pending' => 'test']
        ]);
    }

    public function testTxDefaultProfileUsesAmpereWithoutPhaseSwitching(): void
    {
        $message = $this->invokePrivate('getSetChargingProfileRequest', [1, 16.0, 'TxDefaultProfile', null]);

        $this->assertSame(OCPPEASEE_CALL, $message[0]);
        $this->assertSame('SetChargingProfile', $message[2]);
        $this->assertSame(1, $message[3]['connectorId']);

        $profile = $message[3]['csChargingProfiles'];
        $this->assertSame('TxDefaultProfile', $profile['chargingProfilePurpose']);
        $this->assertSame('Absolute', $profile['chargingProfileKind']);
        $this->assertSame('A', $profile['chargingSchedule']['chargingRateUnit']);
        $this->assertSame(16.0, $profile['chargingSchedule']['chargingSchedulePeriod'][0]['limit']);
        $this->assertArrayNotHasKey('numberPhases', $profile['chargingSchedule']['chargingSchedulePeriod'][0]);
        $this->assertArrayNotHasKey('transactionId', $profile);
    }

    public function testTxProfileContainsTransactionId(): void
    {
        $message = $this->invokePrivate('getSetChargingProfileRequest', [1, 10.0, 'TxProfile', 4711]);

        $this->assertSame(4711, $message[3]['csChargingProfiles']['transactionId']);
    }

    public function testGetConfigurationRequestsOnlyOneKey(): void
    {
        $message = $this->invokePrivate('getConfigurationRequest', ['MeterValueSampleInterval']);

        $this->assertSame('GetConfiguration', $message[2]);
        $this->assertSame(['MeterValueSampleInterval'], $message[3]['key']);
    }

    public function testClearProfileTargetsConfiguredProfileId(): void
    {
        $message = $this->invokePrivate('getClearChargingProfileRequest', [1000]);

        $this->assertSame('ClearChargingProfile', $message[2]);
        $this->assertSame(['id' => 1000], $message[3]);
    }

    public function testCurrentAboveConfiguredMaximumIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->instance->SetChargingCurrent(33.0, 1);
    }

    public function testDoesNotOverrideLegacyMigrateSignature(): void
    {
        $this->assertFalse((new ReflectionClass($this->instance))->hasMethod('Migrate'));
    }

    public function testFindsUserInCentralIdTagList(): void
    {
        $user = $this->invokePrivate('findIdTagUserInLists', [
            '04AABBCC',
            [
                [
                    ['IdTag' => '04AABBCC', 'FirstName' => 'Erika', 'LastName' => 'Muster', 'EMail' => 'erika@example.com']
                ],
                [
                    ['IdTag' => '04AABBCC', 'FirstName' => 'Local', 'LastName' => 'Fallback', 'EMail' => 'local@example.com']
                ]
            ]
        ]);

        $this->assertSame('Erika', $user['FirstName']);
        $this->assertSame('Muster', $user['LastName']);
        $this->assertSame('erika@example.com', $user['EMail']);
    }

    public function testReturnsNullForUnknownIdTag(): void
    {
        $this->assertNull($this->invokePrivate('findIdTagUserInLists', ['UNKNOWN', [[]]]));
    }

    private function invokePrivate(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($this->instance, $method);

        return $reflection->invokeArgs($this->instance, $arguments);
    }
}
