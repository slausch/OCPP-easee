<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/OCPPConstants.php';

class OCPPEaseeChargingPoint extends IPSModule
{
    private const START_AUTOMATIC = -1;
    private const START_ID_ALL = 0;
    private const START_ID_CENTRAL = 1;
    private const START_ID_LOCAL = 2;
    private const START_ID_BOTH = 3;
    private const START_MANUALLY = 4;
    private const SMART_CHARGING_PURPOSES = [
        'TxDefaultProfile',
        'TxProfile',
        'ChargePointMaxProfile'
    ];

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyString('ChargePointIdentity', '');
        $this->RegisterPropertyInteger('ValidateIdTag', 0);
        $this->RegisterPropertyString('ValidIdTagList', '[]');
        $this->RegisterPropertyInteger('DefaultConnectorId', 1);
        $this->RegisterPropertyInteger('ChargingProfileId', 1000);
        $this->RegisterPropertyInteger('ChargingProfileStackLevel', 0);
        $this->RegisterPropertyFloat('MaximumChargingCurrent', 32.0);

        //Variables
        $this->RegisterVariableString('Vendor', $this->Translate('Vendor'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 1);
        $this->RegisterVariableString('Model', $this->Translate('Model'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 2);
        $this->RegisterVariableString('SerialNumber', $this->Translate('Serial Number'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 3);
        $this->RegisterVariableString('IdTag', $this->Translate('Last Id Tag'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 4);
        $this->RegisterVariableFloat('ChargingCurrent', $this->Translate('Charging current limit'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 0,
            'MAX'          => $this->ReadPropertyFloat('MaximumChargingCurrent'),
            'STEP_SIZE'    => 1,
            'PERCENTAGE'   => false,
            'DIGITS'       => 1,
            'USAGE_TYPE'   => 5,
            'SUFFIX'       => ' A'
        ], 10);
        $this->EnableAction('ChargingCurrent');
        $this->RegisterVariableString('LastCommand', $this->Translate('Last OCPP command'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 11);
        $this->RegisterVariableString('LastCommandStatus', $this->Translate('Last command status'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 12);
        $this->RegisterVariableString('LastCommandResponse', $this->Translate('Last command response'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 13);
        $this->RegisterVariableString('Configuration', $this->Translate('OCPP configuration'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 14);
        $this->RegisterVariableString('CompositeSchedule', $this->Translate('Composite schedule'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], 15);
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

        // Filter only our ChargePoint
        $this->SetReceiveDataFilter('.*' . preg_quote($this->ReadPropertyString('ChargePointIdentity'), '/') . '.*');
        $this->RegisterVariableFloat('ChargingCurrent', $this->Translate('Charging current limit'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 0,
            'MAX'          => $this->ReadPropertyFloat('MaximumChargingCurrent'),
            'STEP_SIZE'    => 1,
            'PERCENTAGE'   => false,
            'DIGITS'       => 1,
            'USAGE_TYPE'   => 5,
            'SUFFIX'       => ' A'
        ], 10);
        $this->EnableAction('ChargingCurrent');
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Message']) || !is_array($data['Message'])) {
            $this->SendDebug('Invalid', 'Malformed parent data', 0);
            return '';
        }

        $message = $data['Message'];
        $this->SendDebug('Received', json_encode($message), 0);
        if (count($message) < 3) {
            $this->SendDebug('Invalid', 'Malformed OCPP-J message', 0);
            return '';
        }

        if ($message[0] == OCPPEASEE_CALLRESULT) {
            $this->processCallResult((string) $message[1], (array) $message[2]);
            return '';
        }

        if ($message[0] == OCPPEASEE_CALLERROR) {
            $this->processCallError(
                (string) $message[1],
                (string) ($message[2] ?? 'InternalError'),
                (string) ($message[3] ?? ''),
                (array) ($message[4] ?? [])
            );
            return '';
        }

        if ($message[0] != OCPPEASEE_CALL || count($message) < 4) {
            $this->SendDebug('Skipping', json_encode($message), 0);
            return '';
        }

        $messageID = (string) $message[1];
        $messageType = (string) $message[2];
        $payload = (array) $message[3];

        $result = '';
        switch ($messageType) {
            case 'BootNotification':
                $this->SetValue('Vendor', $payload['chargePointVendor'] ?? '');
                $this->SetValue('Model', $payload['chargePointModel'] ?? '');
                $this->SetValue('SerialNumber', $payload['chargePointSerialNumber'] ?? '');
                // No Feedback. Feedback is sent by the Splitter
                break;
            case 'MeterValues':
                $this->processMeterValue($messageID, $payload);
                break;
            case 'StatusNotification':
                $this->processStatusNotification($messageID, $payload);
                break;
            case 'StartTransaction':
                $this->processStartTransaction($messageID, $payload);
                break;
            case 'StopTransaction':
                $result = json_encode($this->processStopTransaction($messageID, $payload));
                break;
            case 'Authorize':
                $this->processAuthorize($messageID, $payload);
                break;
            case 'ChangeAvailability':
                $this->processChangeAvailability($messageID, $payload);
                break;
            case 'Heartbeat':
                $this->send($this->getHeartbeatResponse($messageID));
                break;
            case 'DataTransfer':
                $this->send($this->getDataTransferResponse($messageID, 'Rejected'));
                break;
            default:
                $this->send($this->getCallErrorResponse($messageID, 'NotImplemented', 'Message is not implemented'));
                break;
        }

        return $result;
    }

    public function RequestAction($Ident, $Value)
    {

        $ConnectorId = 0;
        $parts = explode('_', $Ident);
        if (count($parts) > 1) {
            $Ident = $parts[0];
            $ConnectorId = $parts[1];
        }

        switch ($Ident) {
            case 'Available':
                $this->send($this->getChangeAvailabilityRequest((int) $ConnectorId, $Value ? 'Operative' : 'Inoperative'));
                break;
            case 'ChargingCurrent':
                $this->SetChargingCurrent((float) $Value);
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form['elements'][2]['visible'] = in_array($this->ReadPropertyInteger('ValidateIdTag'), [self::START_ID_LOCAL, self::START_ID_BOTH]);
        return json_encode($form);
    }

    public function Update()
    {
        $this->send($this->getTriggerMessageRequest('BootNotification'));
        $this->send($this->getTriggerMessageRequest('MeterValues'));
        $this->send($this->getTriggerMessageRequest('StatusNotification'));
        $this->RefreshConfiguration();
    }

    public function SetChargingCurrent(float $Current, int $ConnectorId = 0)
    {
        return $this->SetChargingProfile($Current, $ConnectorId, 'TxDefaultProfile');
    }

    public function SetChargingProfile(float $Current, int $ConnectorId = 0, string $Purpose = 'TxDefaultProfile')
    {
        if ($Current < 0 || $Current > $this->ReadPropertyFloat('MaximumChargingCurrent')) {
            throw new InvalidArgumentException(sprintf(
                'Charging current must be between 0 and %.1f A',
                $this->ReadPropertyFloat('MaximumChargingCurrent')
            ));
        }
        if (!in_array($Purpose, self::SMART_CHARGING_PURPOSES, true)) {
            throw new InvalidArgumentException('Unsupported charging profile purpose');
        }

        $connectorId = $Purpose === 'ChargePointMaxProfile'
            ? 0
            : $this->normalizeConnectorId($ConnectorId);

        $transactionId = null;
        if ($Purpose === 'TxProfile') {
            $transactionId = $this->getCurrentTransactionId($connectorId);
            if ($transactionId === null) {
                throw new RuntimeException('TxProfile requires an active transaction');
            }
        }

        return $this->send(
            $this->getSetChargingProfileRequest($connectorId, $Current, $Purpose, $transactionId),
            [
                'connectorId' => $connectorId,
                'current'     => $Current,
                'purpose'     => $Purpose,
                'profileId'   => $this->ReadPropertyInteger('ChargingProfileId')
            ]
        );
    }

    public function ClearChargingProfile(int $ProfileId = 0)
    {
        $profileId = $ProfileId > 0 ? $ProfileId : $this->ReadPropertyInteger('ChargingProfileId');
        if ($profileId < 0) {
            throw new InvalidArgumentException('Charging profile id must not be negative');
        }

        return $this->send($this->getClearChargingProfileRequest($profileId), [
            'profileId' => $profileId
        ]);
    }

    public function GetCompositeSchedule(int $ConnectorId = 0, int $Duration = 86400)
    {
        if ($Duration < 1) {
            throw new InvalidArgumentException('Duration must be greater than zero');
        }

        return $this->send($this->getCompositeScheduleRequest(
            $this->normalizeConnectorId($ConnectorId),
            $Duration
        ));
    }

    public function GetOCPPConfiguration(string $Key)
    {
        $key = trim($Key);
        if ($key === '') {
            throw new InvalidArgumentException('Configuration key must not be empty');
        }

        return $this->send($this->getConfigurationRequest($key), ['key' => $key]);
    }

    public function ChangeConfiguration(string $Key, string $Value)
    {
        $key = trim($Key);
        if ($key === '') {
            throw new InvalidArgumentException('Configuration key must not be empty');
        }

        return $this->send($this->getChangeConfigurationRequest($key, $Value), [
            'key'   => $key,
            'value' => $Value
        ]);
    }

    public function SetMeterValueSampleInterval(int $Seconds)
    {
        if ($Seconds !== 0 && ($Seconds < 30 || $Seconds > 3600)) {
            throw new InvalidArgumentException('Meter value sample interval must be 0 or between 30 and 3600 seconds');
        }

        return $this->ChangeConfiguration('MeterValuesSampleInterval', (string) $Seconds);
    }

    public function RefreshConfiguration()
    {
        $messageIds = [];
        foreach ([
            'SupportedFeatureProfiles',
            'MeterValuesSampleInterval',
            'ClockAlignedDataInterval',
            'MeterValuesSampledData',
            'MeterValuesAlignedData',
            'ChargeProfileMaxStackLevel',
            'ChargingScheduleAllowedChargingRateUnit',
            'ChargingScheduleMaxPeriods',
            'MaxChargingProfilesInstalled'
        ] as $key) {
            $messageIds[] = $this->GetOCPPConfiguration($key);
        }

        return json_encode($messageIds);
    }

    public function RemoteStartTransaction(int $ConnectorId)
    {
        $idTag = 'symcon';
        $this->send($this->getRemoteStartTransactionRequest($ConnectorId, $idTag));
    }

    public function RemoteStopTransaction(int $TransactionId)
    {
        $this->send($this->getRemoteStopTransactionRequest($TransactionId));
    }

    public function RemoteStopCurrentTransaction(int $ConnectorId)
    {
        $ident = sprintf('TransactionID_%d', $ConnectorId);

        // Some Wallboxes do not support proper TransactionID handling, but will react on TransactionID 0
        $id = @$this->GetIDForIdent($ident);
        if ($id === false) {
            $this->send($this->getRemoteStopTransactionRequest(0));
        } else {
            $this->send($this->getRemoteStopTransactionRequest(GetValue($id)));
        }
    }

    public function UIUpdateCP(int $ValidateIdTag)
    {
        $this->UpdateFormField('ValidIdTagList', 'visible', in_array($ValidateIdTag, [self::START_ID_LOCAL, self::START_ID_BOTH]));
    }

    private function getIdTagStatus($idTag)
    {
        $startStrategy = $this->ReadPropertyInteger('ValidateIdTag');
        if ($startStrategy == self::START_AUTOMATIC) {
            return 'Accepted';
        }

        // Our internal RemoteStartTransaction command was used
        if ($idTag == 'symcon') {
            return 'Accepted';
        }

        $centralIdTag = false;
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID > 0) {
            $json = json_decode(IPS_GetProperty($parentID, 'ValidIdTagList'), true);
            foreach ($json as $item) {
                if ($idTag == $item['IdTag']) {
                    $centralIdTag = true;
                    break;
                }
            }
        }

        // Check if IdTag is in our lis
        $localIdTag = false;
        $json = json_decode($this->ReadPropertyString('ValidIdTagList'), true);
        foreach ($json as $item) {
            if ($idTag == $item['IdTag']) {
                $localIdTag = true;
                break;
            }
        }

        switch ($this->ReadPropertyInteger('ValidateIdTag')) {
            case self::START_ID_ALL:
                return 'Accepted';
            case self::START_ID_CENTRAL:
                if ($centralIdTag) {
                    return 'Accepted';
                }
                break;
            case self::START_ID_LOCAL:
                if ($localIdTag) {
                    return 'Accepted';
                }
                break;
            case self::START_ID_BOTH:
                if ($centralIdTag || $localIdTag) {
                    return 'Accepted';
                }
                break;
        }

        return 'Invalid';
    }

    private function send($message, array $context = [])
    {
        if (!is_array($message) || count($message) < 2) {
            throw new InvalidArgumentException('Invalid OCPP-J message');
        }

        $messageId = (string) $message[1];
        if ($message[0] == OCPPEASEE_CALL) {
            $pendingRequests = json_decode($this->GetBuffer('PendingRequests'), true);
            if (!is_array($pendingRequests)) {
                $pendingRequests = [];
            }
            $pendingRequests[$messageId] = [
                'action'  => (string) ($message[2] ?? ''),
                'context' => $context,
                'sentAt'  => time()
            ];
            if (count($pendingRequests) > 50) {
                uasort($pendingRequests, static function ($left, $right)
                {
                    return $left['sentAt'] <=> $right['sentAt'];
                });
                $pendingRequests = array_slice($pendingRequests, -50, null, true);
            }
            $this->SetBuffer('PendingRequests', json_encode($pendingRequests));
            $this->SetValue('LastCommand', (string) ($message[2] ?? ''));
            $this->SetValue('LastCommandStatus', 'Pending');
            $this->SetValue('LastCommandResponse', '');
        }

        $this->SendDebug('Transmitted', json_encode($message), 0);
        $this->SendDataToParent(json_encode([
            'DataID'              => '{77EC111F-2054-4DB5-A9F7-877737764077}',
            'ChargePointIdentity' => $this->ReadPropertyString('ChargePointIdentity'),
            'Message'             => $message
        ]));

        return $messageId;
    }

    private function processCallResult(string $messageId, array $payload)
    {
        $pendingRequests = json_decode($this->GetBuffer('PendingRequests'), true);
        if (!is_array($pendingRequests) || !isset($pendingRequests[$messageId])) {
            $this->SendDebug('Unmatched CALLRESULT', $messageId, 0);
            return;
        }

        $pending = $pendingRequests[$messageId];
        unset($pendingRequests[$messageId]);
        $this->SetBuffer('PendingRequests', json_encode($pendingRequests));

        $action = (string) $pending['action'];
        $context = (array) ($pending['context'] ?? []);
        $status = (string) ($payload['status'] ?? 'Accepted');

        $this->SetValue('LastCommand', $action);
        $this->SetValue('LastCommandStatus', $status);
        $this->SetValue('LastCommandResponse', json_encode($payload));

        switch ($action) {
            case 'SetChargingProfile':
                if ($status === 'Accepted' && isset($context['current'])) {
                    $this->SetValue('ChargingCurrent', (float) $context['current']);
                }
                break;
            case 'GetCompositeSchedule':
                $this->SetValue('CompositeSchedule', json_encode($payload));
                break;
            case 'GetConfiguration':
                $this->storeConfigurationResponse($payload);
                break;
        }
    }

    private function processCallError(string $messageId, string $errorCode, string $description, array $details)
    {
        $pendingRequests = json_decode($this->GetBuffer('PendingRequests'), true);
        $action = 'Unknown';
        if (is_array($pendingRequests) && isset($pendingRequests[$messageId])) {
            $action = (string) $pendingRequests[$messageId]['action'];
            unset($pendingRequests[$messageId]);
            $this->SetBuffer('PendingRequests', json_encode($pendingRequests));
        }

        $this->SetValue('LastCommand', $action);
        $this->SetValue('LastCommandStatus', 'Error: ' . $errorCode);
        $this->SetValue('LastCommandResponse', json_encode([
            'description' => $description,
            'details'     => $details
        ]));
    }

    private function storeConfigurationResponse(array $payload)
    {
        $configuration = json_decode($this->GetValue('Configuration'), true);
        if (!is_array($configuration)) {
            $configuration = [];
        }

        foreach ((array) ($payload['configurationKey'] ?? []) as $item) {
            if (!isset($item['key'])) {
                continue;
            }
            $configuration[$item['key']] = [
                'value'    => (string) ($item['value'] ?? ''),
                'readonly' => (bool) ($item['readonly'] ?? false)
            ];
        }
        foreach ((array) ($payload['unknownKey'] ?? []) as $unknownKey) {
            $configuration[(string) $unknownKey] = [
                'unknown' => true
            ];
        }
        ksort($configuration);
        $this->SetValue('Configuration', json_encode($configuration));
    }

    private function normalizeConnectorId(int $connectorId)
    {
        $connectorId = $connectorId > 0 ? $connectorId : $this->ReadPropertyInteger('DefaultConnectorId');
        if ($connectorId < 1) {
            throw new InvalidArgumentException('Connector id must be greater than zero');
        }

        return $connectorId;
    }

    private function sanitizeIdentPart(string $value)
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $value), '_');
    }

    private function getCurrentTransactionId(int $connectorId)
    {
        $id = @$this->GetIDForIdent(sprintf('TransactionID_%d', $connectorId));
        if ($id === false) {
            return null;
        }

        $transactionId = (int) GetValue($id);
        return $transactionId > 0 ? $transactionId : null;
    }

    private function getStopTransactionResponse(string $messageID, string $status)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 77
         * StopTransaction.conf
         */
        return [
            OCPPEASEE_CALLRESULT,
            $messageID,
            [
                'idTagInfo' => [
                    'status' => $status
                ]
            ]
        ];
    }

    private function getStartTransactionResponse(string $messageID, int $transactionId, string $status)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 76
         * StartTransaction.conf
         */

        return [
            OCPPEASEE_CALLRESULT,
            $messageID,
            [
                'idTagInfo' => [
                    'status' => $status
                ],
                'transactionId' => $transactionId
            ]
        ];
    }

    private function getMeterValueResponse(string $messageID)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 73
         * MeterValues.conf
         */
        return [
            OCPPEASEE_CALLRESULT,
            $messageID,
            new stdClass()
        ];
    }

    private function getStatusNotificationResponse(string $messageID)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 73
         * StatusNotification.conf
         */
        return [
            OCPPEASEE_CALLRESULT,
            $messageID,
            new stdClass()
        ];
    }

    private function getHeartbeatResponse(string $messageID)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 72
         * Heartbeat.conf
         */
        return [
            OCPPEASEE_CALLRESULT,
            $messageID,
            [
                'currentTime' => date(DateTime::ATOM)
            ]
        ];
    }

    private function processMeterValue(string $messageID, $payload)
    {
        $values = (array) ($payload['meterValue'] ?? []);
        if ($values === []) {
            $this->send($this->getMeterValueResponse($messageID));
            return;
        }

        $currentValue = null;
        $currentTime = 0;
        foreach ($values as $value) {
            //If the timestamp is higher than the previous, save it and the value. We only want the newest one
            $newTime = strtotime($value['timestamp'] ?? '');
            if ($newTime !== false && $newTime >= $currentTime) {
                $currentTime = $newTime;
                $currentValue = $value;
            }
        }

        if (!is_array($currentValue)) {
            $this->send($this->getMeterValueResponse($messageID));
            return;
        }

        $connectorId = (int) ($payload['connectorId'] ?? 0);
        foreach ((array) ($currentValue['sampledValue'] ?? []) as $sampledValue) {
            if (!is_array($sampledValue)) {
                continue;
            }
            $measurand = (string) ($sampledValue['measurand'] ?? 'Energy.Active.Import.Register');
            $phase = (string) ($sampledValue['phase'] ?? '');
            $location = (string) ($sampledValue['location'] ?? '');

            $identParts = [$measurand];
            $nameParts = [$measurand];
            if ($phase !== '') {
                $identParts[] = $phase;
                $nameParts[] = $phase;
            }
            if ($location !== '') {
                $identParts[] = $location;
                $nameParts[] = $location;
            }

            $ident = sprintf(
                'MeterValue_%d_%s',
                $connectorId,
                $this->sanitizeIdentPart(implode('_', $identParts))
            );
            $suffixName = ', ' . implode(', ', $nameParts);
            $this->RegisterVariableFloat($ident, sprintf($this->Translate('Meter Value (Connector %d)%s'), $connectorId, $suffixName), [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => ' ' . ($sampledValue['unit'] ?? 'Wh')
            ], ($connectorId + 1) * 100 + 10);
            $this->SetValue($ident, (float) ($sampledValue['value'] ?? 0));
        }

        $this->send($this->getMeterValueResponse($messageID));
    }

    private function processStatusNotification(string $messageID, $payload)
    {
        $connectorId = (int) ($payload['connectorId'] ?? 0);
        $status = (string) ($payload['status'] ?? 'Unknown');
        $errorCode = (string) ($payload['errorCode'] ?? 'NoError');

        $ident = sprintf('Available_%d', $connectorId);
        $this->RegisterVariableBoolean($ident, sprintf($this->Translate('Available (Connector %d)'), $connectorId), [
            'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
            'USAGE_TYPE'     => 0,
            'USE_ICON_FALSE' => false,
            'ICON_TRUE'      => 'plug'
        ], ($connectorId + 1) * 100);
        $this->EnableAction($ident);
        $this->SetValue($ident, $status != 'Unavailable');

        $ident = sprintf('Status_%d', $connectorId);
        $this->RegisterVariableString($ident, sprintf($this->Translate('Status (Connector %d)'), $connectorId), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], ($connectorId + 1) * 100 + 1);
        $this->SetValue($ident, $status);

        $ident = sprintf('ErrorCode_%d', $connectorId);
        $this->RegisterVariableString($ident, sprintf($this->Translate('ErrorCode (Connector %d)'), $connectorId), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], ($connectorId + 1) * 100 + 2);
        $this->SetValue($ident, $errorCode);

        $this->send($this->getStatusNotificationResponse($messageID));

        // Take care of the 'Preparing' status which might want to trigger us the RemoteStartTransaction
        if ($status === 'Preparing') {
            if ($this->ReadPropertyInteger('ValidateIdTag') == self::START_AUTOMATIC) {
                $this->RemoteStartTransaction($connectorId);
            }
        }
    }

    private function processStartTransaction(string $messageID, $payload)
    {
        $ident = sprintf('Transaction_%d', $payload['connectorId']);
        $this->RegisterVariableBoolean($ident, sprintf($this->Translate('Transaction (Connector %d)'), $payload['connectorId']), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode([
                [
                    'Value'       => false,
                    'Caption'     => $this->Translate('Inactive'),
                    'IconActive'  => true,
                    'IconValue'   => 'bolt-slash',
                    'ColorActive' => false,
                    'ColorValue'  => -1
                ],
                [
                    'Value'       => true,
                    'Caption'     => $this->Translate('Active'),
                    'IconActive'  => true,
                    'IconValue'   => 'bolt',
                    'ColorActive' => false,
                    'ColorValue'  => -1
                ]
            ])
        ], ($payload['connectorId'] + 1) * 100 + 3);
        $this->SetValue($ident, true);

        // Transaction_* > OCPP Values
        // Transaction* > Internal values (without underscore!)

        $transactionId = $this->generateTransactionID();
        $ident = sprintf('TransactionID_%d', $payload['connectorId']);
        $this->RegisterVariableInteger($ident, sprintf($this->Translate('Transaction Id (Connector %d)'), $payload['connectorId']), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], ($payload['connectorId'] + 1) * 100 + 4);
        $this->SetValue($ident, $transactionId);

        $ident = sprintf('Transaction_Meter_Start_%d', $payload['connectorId']);
        $this->RegisterVariableInteger($ident, sprintf($this->Translate('Transaction Meter Start (Connector %d)'), $payload['connectorId']), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' Wh'
        ], ($payload['connectorId'] + 1) * 100 + 5);
        $this->SetValue($ident, $payload['meterStart']);

        $ident = sprintf('Transaction_Meter_End_%d', $payload['connectorId']);
        $this->RegisterVariableInteger($ident, sprintf($this->Translate('Transaction Meter End (Connector %d)'), $payload['connectorId']), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' Wh'
        ], ($payload['connectorId'] + 1) * 100 + 5);
        $this->SetValue($ident, 0);

        $ident = sprintf('Transaction_ID_Tag_%d', $payload['connectorId']);
        $this->RegisterVariableString($ident, sprintf($this->Translate('Transaction Id Tag (Connector %d)'), $payload['connectorId']), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ], ($payload['connectorId'] + 1) * 100 + 6);

        // Workaround: Alfen is sending a wrong IdTag. We need to use the IdTag from the last authorization
        if ($this->GetValue('Vendor') == 'Alfen BV') {
            $payload['idTag'] = $this->GetValue('IdTag');
        }

        $this->SetValue($ident, $payload['idTag']);

        $ident = sprintf('TransactionConsumption_%d', $payload['connectorId']);
        $this->RegisterVariableInteger($ident, sprintf($this->Translate('Transaction Consumption (Connector %d)'), $payload['connectorId']), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' Wh'
        ], ($payload['connectorId'] + 1) * 100 + 5);
        $this->SetValue($ident, 0);

        $this->send($this->getStartTransactionResponse($messageID, $transactionId, $this->getIdTagStatus($payload['idTag'])));
    }

    private function processStopTransaction(string $messageID, $payload)
    {
        // Stop Transaction does not transmit the connectorId. We need to search it by the TransactionID.
        $connectorId = false;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            if (IPS_VariableExists($id)) {
                $o = IPS_GetObject($id);
                if (substr($o['ObjectIdent'], 0, 13) == 'TransactionID') {
                    if (GetValue($id) == $payload['transactionId']) {
                        $connectorId = str_replace('TransactionID_', '', $o['ObjectIdent']);
                    }
                }
            }
        }

        if ($connectorId === false) {
            $this->SendDebug('Error', 'TransactionID not found', 0);
            return;
        }

        // Update transaction values
        $this->SetValue(sprintf('Transaction_%d', $connectorId), false);
        $this->SetValue(sprintf('TransactionID_%d', $connectorId), 0);
        $this->SetValue(sprintf('Transaction_Meter_End_%d', $connectorId), $payload['meterStop']);
        $this->SetValue(sprintf('TransactionConsumption_%d', $connectorId), $payload['meterStop'] - $this->GetValue(sprintf('Transaction_Meter_Start_%d', $connectorId)));

        // The idTag might not be defined (Wallbox restarted and had to stop the transaction)
        // Therefore we can only validate if it is set (e.g. another RFID card was used to stop a running transaction)
        $status = isset($payload['idTag']) ? $this->getIdTagStatus($payload['idTag']) : 'Accepted';

        $this->send($this->getStopTransactionResponse($messageID, $status));

        // Return consumption data to properly forward it to the splitter
        return [
            'IdTag'       => $this->GetValue(sprintf('Transaction_ID_Tag_%d', $connectorId)),
            'Consumption' => $this->GetValue(sprintf('TransactionConsumption_%d', $connectorId)),
        ];
    }

    private function processAuthorize(string $messageID, $payload)
    {
        $status = $this->getIdTagStatus($payload['idTag']);

        // We only want to remember the last successful IdTag
        // Normally the IdTag is only transmitted on StartTransaction,
        // but some ChargePoints (e.g. Alfen) do not transmit it there
        // Therefore we need to just remember it and use it there
        if ($status == 'Accepted') {
            $this->SetValue('IdTag', $payload['idTag']);
        }
        else {
            $this->SetValue('IdTag', '');
        }

        $this->send($this->getAuthorizeResponse($messageID, $status));
    }

    private function processChangeAvailability(string $messageID, $payload)
    {
        // Nothing to do yet
    }

    private function getDataTransferResponse(string $messageID, string $status)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 64
         * DataTransfer.conf
         */
        return [
            OCPPEASEE_CALLRESULT,
            $messageID,
            [
                'status' => $status
            ]
        ];
    }

    private function getCallErrorResponse(string $messageID, string $errorCode, string $description)
    {
        return [
            OCPPEASEE_CALLERROR,
            $messageID,
            $errorCode,
            $description,
            new stdClass()
        ];
    }

    private function getAuthorizeResponse(string $messageID, string $status)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 64
         * Authorize.conf
         */
        return [
            OCPPEASEE_CALLRESULT,
            $messageID,
            [
                'idTagInfo' => [
                    'status' => $status
                ],
            ]
        ];
    }

    private function generateMessageID()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    private function generateTransactionID()
    {
        return rand(1, 5000);
    }

    private function getSetChargingProfileRequest(
        int $connectorId,
        float $current,
        string $purpose,
        ?int $transactionId
    ) {
        $profile = [
            'chargingProfileId'      => $this->ReadPropertyInteger('ChargingProfileId'),
            'stackLevel'             => $this->ReadPropertyInteger('ChargingProfileStackLevel'),
            'chargingProfilePurpose' => $purpose,
            'chargingProfileKind'    => 'Absolute',
            'chargingSchedule'       => [
                'chargingRateUnit'       => 'A',
                'chargingSchedulePeriod' => [
                    [
                        'startPeriod' => 0,
                        'limit'       => $current
                    ]
                ]
            ]
        ];
        if ($transactionId !== null) {
            $profile['transactionId'] = $transactionId;
        }

        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'SetChargingProfile',
            [
                'connectorId'        => $connectorId,
                'csChargingProfiles' => $profile
            ]
        ];
    }

    private function getClearChargingProfileRequest(int $profileId)
    {
        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'ClearChargingProfile',
            [
                'id' => $profileId
            ]
        ];
    }

    private function getCompositeScheduleRequest(int $connectorId, int $duration)
    {
        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'GetCompositeSchedule',
            [
                'connectorId'      => $connectorId,
                'duration'         => $duration,
                'chargingRateUnit' => 'A'
            ]
        ];
    }

    private function getConfigurationRequest(string $key)
    {
        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'GetConfiguration',
            [
                'key' => [$key]
            ]
        ];
    }

    private function getChangeConfigurationRequest(string $key, string $value)
    {
        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'ChangeConfiguration',
            [
                'key'   => $key,
                'value' => $value
            ]
        ];
    }

    private function getTriggerMessageRequest(string $messageType)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 89
         * TriggerMessage.req
         */
        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'TriggerMessage',
            [
                'requestedMessage' => $messageType
            ]
        ];
    }

    private function getRemoteStartTransactionRequest(int $connectorId, string $idTag /* unsupported for now: array $chargingProfile */)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 80
         * RemoteStartTransaction.req
         */
        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'RemoteStartTransaction',
            [
                'connectorId' => $connectorId,
                'idTag'       => $idTag
            ]
        ];
    }

    private function getRemoteStopTransactionRequest(int $transactionId)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 81
         * RemoteStopTransaction.req
         */
        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'RemoteStopTransaction',
            [
                'transactionId' => $transactionId
            ]
        ];
    }

    private function getChangeAvailabilityRequest(int $connectorId, string $type)
    {
        /**
         * OCPP-1.6 edition 2.pdf
         * Page 65
         * ChangeAvailability.req
         */
        return [
            OCPPEASEE_CALL,
            $this->generateMessageID(),
            'ChangeAvailability',
            [
                'connectorId' => $connectorId,
                'type'        => $type,
            ]
        ];
    }
}
