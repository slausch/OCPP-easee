# OCPP easee

Eigenständiges OCPP-1.6J-Central-System-Modul für IP-Symcon mit den für Easee
Direct OCPP benötigten Smart-Charging-Funktionen. Das Modul ist für IP-Symcon
8.0 oder neuer vorgesehen und kann durch eigene Library-, Modul- und Interface-
IDs parallel zum offiziellen Symcon-Modul `OCPP` installiert werden.

## Herkunft der Codebasis

Mit Zustimmung von [@paresy](https://github.com/paresy) wurde die Code-Basis
vom offiziellen [OCPP-Symcon-Modul](https://github.com/symcon/ocpp) übernommen.
Später wird sie gegebenenfalls wieder zusammengeführt.

Der Ausgangsstand ist Commit `826fd4a999549df3dd94388bc1716ba8dc943751`
des offiziellen Repositories.

## Geplanter Funktionsumfang

- OCPP 1.6J über WebSocket als Central System
- Empfang und Auswertung von Boot-, Status-, Transaktions- und MeterValues-
  Nachrichten
- Smart Charging über `SetChargingProfile`, `ClearChargingProfile` und
  `GetCompositeSchedule`
- Ampere-basierte `TxDefaultProfile`-, `TxProfile`- und
  `ChargePointMaxProfile`-Profile einschließlich 0 A zum Pausieren
- Lesen und Ändern unterstützter OCPP-Konfigurationswerte
- Remote Start/Stop sowie TriggerMessage

Die Easee-spezifische Umschaltung zwischen ein- und dreiphasigem Laden ist
nicht Bestandteil dieses Moduls, da Easee sie in Direct OCPP nicht anbietet.

## Referenzgerät

- Easee Charge Pro Eichrecht
- Firmware v343
- Easee Direct OCPP, Q2-2026-Funktionsstand

Die nicht öffentliche Easee-Unterlage wird nicht mit diesem Repository
veröffentlicht oder eingecheckt.

## Enthaltene Module

- **OCPP easee Splitter** - WebSocket-Kommunikation
- **OCPP easee Configurator** - Erkennung und Einrichtung von Ladepunkten
- **OCPP easee Charging Point** - Ladepunkt, Messwerte und Steuerung

## Variablen und Darstellungen

Das Modul verwendet die seit IP-Symcon 8 verfügbaren Präsentationsdefinitionen
direkt bei der Variablenregistrierung. Es legt keine klassischen globalen
Variablenprofile an. Der Ladestrom wird als Slider in Ampere dargestellt;
Status-, Messwert- und Ergebnisvariablen folgen dem Vorgehen der übernommenen
aktuellen OCPP-Codebasis.

## Smart-Charging-Schnittstelle

Für Skripte stehen unter dem Präfix `OCPPEASEE_` unter anderem folgende
Funktionen zur Verfügung:

- `SetChargingCurrent` für ein dauerhaftes `TxDefaultProfile`
- `SetChargingProfile` für `TxDefaultProfile`, `TxProfile` oder
  `ChargePointMaxProfile`
- `ClearChargingProfile` und `GetCompositeSchedule`
- `GetOCPPConfiguration`, `ChangeConfiguration` und
  `SetMeterValueSampleInterval`
- `RemoteStartTransaction`, `RemoteStopTransaction` und
  `RemoteStopCurrentTransaction`

Ein Grenzwert von 0 A pausiert die Leistungsfreigabe über das Ladeprofil. Das
Modul setzt ausschließlich Ampere-basierte Ladepläne und sendet bewusst keine
Vorgabe zur Phasenanzahl.

## Entwicklungs- und Teststand

Metadaten, JSON-Dateien, PHP-Syntax und die OCPP-Payloads werden automatisiert
geprüft. Vor einer produktiven Nutzung ist noch ein Integrationstest mit dem
Referenzgerät erforderlich. Dabei werden insbesondere WebSocket-Verbindung,
Authentifizierung/TLS, die tatsächlich gelieferten MeterValues sowie die
Antworten auf Smart-Charging-Befehle verifiziert.

OCPP-Telegramme können während dieses Tests in der Debug-Ausgabe der Splitter-
und Ladepunktinstanz eingesehen werden. Dadurch müssen vorab keine Nachrichten
manuell erzeugt werden; sensible Kennungen sollten vor einer Weitergabe aus der
Debug-Ausgabe entfernt werden.
