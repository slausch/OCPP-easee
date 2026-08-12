<?php

declare(strict_types=1);

class OCPPEaseeConfigurator extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->SetReceiveDataFilter('.*BootNotification.*');

        $this->RequireParent('{FFD75EB7-E366-4648-AA4A-F973DF62E7A0}');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();
    }

    public function ReceiveData($JSON)
    {
        $data = json_decode($JSON, true);

        //store the received BootNotification payload in a Buffer
        $this->SetBuffer($data['ChargePointIdentity'], json_encode($data['Message'][3]));

        return $this->InstanceID;
    }

    public function GetConfigurationForm()
    {
        /**
         * Payload of BootMessage
         * OCPP-j-1.6-edition 2.pdf Page 65 BootNotification.req
         */
        $availableChargePoints = [];

        //Get the points who send BootMessages
        $bufferList = $this->GetBufferList();
        foreach ($bufferList as $chargePointIdentity) {
            $buffer = $this->GetBuffer($chargePointIdentity);
            $payload = json_decode($buffer, true);
            $availableChargePoints[] = [
                'Vendor'              => $payload['chargePointVendor'] ?? '',
                'Model'               => $payload['chargePointModel'] ?? '',
                'SerialNumber'        => $payload['chargePointSerialNumber'] ?? '',
                'ChargePointIdentity' => $chargePointIdentity,
                'create'              => [
                    'name'          => $chargePointIdentity,
                    'moduleID'      => '{79BFA163-9D95-4D88-91C4-65F11FFBA15A}',
                    'configuration' => [
                        'ChargePointIdentity' => $chargePointIdentity,
                    ]
                ]
            ];
        }

        //Get the Instance and set the right ids or add it to the list
        foreach (IPS_GetInstanceListByModuleID('{79BFA163-9D95-4D88-91C4-65F11FFBA15A}') as $instanceID) {
            if (IPS_GetInstance($this->InstanceID)['ConnectionID'] !== IPS_GetInstance($instanceID)['ConnectionID']) {
                continue;
            }
            $found = false;
            foreach ($availableChargePoints as $index => $availableChargePoint) {
                if ($availableChargePoint['ChargePointIdentity'] == IPS_GetProperty($instanceID, 'ChargePointIdentity')) {
                    $availableChargePoints[$index]['instanceID'] = $instanceID;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $availableChargePoints[] = [
                    'Vendor'                 => GetValue(IPS_GetObjectIDByIdent('Vendor', $instanceID)),
                    'Model'                  => GetValue(IPS_GetObjectIDByIdent('Model', $instanceID)),
                    'SerialNumber'           => GetValue(IPS_GetObjectIDByIdent('SerialNumber', $instanceID)),
                    'ChargePointIdentity'    => IPS_GetProperty($instanceID, 'ChargePointIdentity'),
                    'instanceID'             => $instanceID,
                    'create'                 => [
                        'moduleID'      => '{79BFA163-9D95-4D88-91C4-65F11FFBA15A}',
                        'configuration' => [
                            'ChargePointIdentity' => IPS_GetProperty($instanceID, 'ChargePointIdentity'),
                        ]
                    ],
                ];
            }
        }

        return json_encode([
            'actions' => [
                [
                    'type'    => 'Configurator',
                    'caption' => 'Charging Points',
                    'columns' => [
                        [
                            'name'    => 'Vendor',
                            'caption' => 'Vendor',
                            'width'   => 'auto'
                        ],
                        [
                            'name'    => 'Model',
                            'caption' => 'Model',
                            'width'   => '200px',
                        ],
                        [
                            'name'    => 'SerialNumber',
                            'caption' => 'Serial Number',
                            'width'   => '200px'
                        ],
                        [
                            'name'    => 'ChargePointIdentity',
                            'caption' => 'Charge Point Identity',
                            'width'   => '200px'
                        ]

                    ],
                    'values' => $availableChargePoints
                ]
            ]
        ]);
    }
}
