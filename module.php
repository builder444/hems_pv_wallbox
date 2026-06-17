<?php

class PVWallboxController extends IPSModule
{
    public function Create()
    {
        parent::Create();

        /* ===============================
         * Modbus – SMA Home Manager
         * =============================== */
        $this->RegisterPropertyInteger("SMA_Instance", 0);
        $this->RegisterPropertyInteger("SMA_RegPV", 0);
        $this->RegisterPropertyInteger("SMA_RegHouse", 0);
        $this->RegisterPropertyInteger("SMA_RegSoC", 0);
        $this->RegisterPropertyInteger("SMA_RegBatteryPower", 0);

        /* ===============================
         * Modbus – Mennekes
         * =============================== */
        $this->RegisterPropertyInteger("MEN_Instance", 0);
        $this->RegisterPropertyInteger("MEN_RegHEMS", 0);

        /* ===============================
         * Regelparameter
         * =============================== */
        $this->RegisterPropertyInteger("MinSoC", 70);
        $this->RegisterPropertyInteger("MinAmpere", 6);
        $this->RegisterPropertyInteger("MaxAmpere", 16);
        $this->RegisterPropertyInteger("PVBufferSize", 10);
        $this->RegisterPropertyInteger("HoldTime", 60);

        /* ===============================
         * Status / WebFront
         * =============================== */
        $this->RegisterVariableBoolean("AutoMode", "Automatik", "~Switch");
        $this->RegisterVariableInteger("ManualAmpere", "Manuell Strom (A)", "~Intensity.100");
        $this->RegisterVariableInteger("CurrentAmpere", "Aktueller Strom (A)", "~Intensity.100");
        $this->RegisterVariableFloat("PVSmoothed", "PV geglättet (W)");
        $this->RegisterVariableString("Status", "Status");

        /* ===============================
         * Timer
         * =============================== */
        $this->RegisterTimer(
            "UpdateTimer",
            10000,
            "PVWB_Update(\$_IPS['TARGET']);"
        );
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    /* ===============================
     * Hauptlogik
     * =============================== */
    public function Update()
    {
        /* ---------- Auto / Manuell ---------- */
        if (!GetValue($this->GetIDForIdent("AutoMode"))) {
            $amp = GetValue($this->GetIDForIdent("ManualAmpere"));
            $this->SetWallbox($amp);
            $this->SetStatus("MANUELL: {$amp} A");
            return;
        }

        /* ---------- SMA Werte ---------- */
        $pv = $this->ReadFloat("SMA_Instance", "SMA_RegPV");
        $house = $this->ReadFloat("SMA_Instance", "SMA_RegHouse");
        $soc = $this->ReadFloat("SMA_Instance", "SMA_RegSoC");
        $batPower = $this->ReadFloat("SMA_Instance", "SMA_RegBatteryPower");

        /* ---------- PV Glättung ---------- */
        $pvSmooth = $this->SmoothPV($pv);
        SetValue($this->GetIDForIdent("PVSmoothed"), $pvSmooth);

        /* ---------- SoC Schutz ---------- */
        if ($soc < $this->ReadPropertyInteger("MinSoC")) {
            $this->SetWallbox(0);
            $this->SetStatus("STOPP: Batterie {$soc}%");
            return;
        }

        /* ---------- Überschuss ---------- */
        $available = $pvSmooth - $house - max(0, $batPower);
        if ($available < 1500) {
            $this->SetWallbox(0);
            $this->SetStatus("STOPP: Kein Überschuss");
            return;
        }

        /* ---------- Ampere berechnen ---------- */
        $amp = floor($available / (230 * 3));
        $amp = max(
            $this->ReadPropertyInteger("MinAmpere"),
            min($amp, $this->ReadPropertyInteger("MaxAmpere"))
        );

        /* ---------- Taktschutz ---------- */
        $lastAmp = GetValue($this->GetIDForIdent("CurrentAmpere"));
        $lastChange = intval($this->GetBuffer("LastChange"));

        if ($amp !== $lastAmp && (time() - $lastChange) < $this->ReadPropertyInteger("HoldTime")) {
            $this->SetStatus("TAKTSCHUTZ: halte {$lastAmp} A");
            return;
        }

        if ($amp !== $lastAmp) {
            $this->SetBuffer("LastChange", time());
        }

        /* ---------- Schreiben ---------- */
        $this->SetWallbox($amp);
        $this->SetStatus("AUTO: {$amp} A");
    }

    /* ===============================
     * Hilfsfunktionen
     * =============================== */

    private function SmoothPV(float $value): float
    {
        $buffer = json_decode($this->GetBuffer("PVBuffer"), true) ?? [];
        $buffer[] = $value;

        $max = $this->ReadPropertyInteger("PVBufferSize");
        if (count($buffer) > $max) {
            array_shift($buffer);
        }

        $this->SetBuffer("PVBuffer", json_encode($buffer));
        return array_sum($buffer) / count($buffer);
    }

    private function SetWallbox(int $amp)
    {
        $this->WriteInt("MEN_Instance", "MEN_RegHEMS", $amp);
        SetValue($this->GetIDForIdent("CurrentAmpere"), $amp);
    }

    private function SetStatus(string $txt)
    {
        SetValue($this->GetIDForIdent("Status"), $txt);
    }

    private function ReadFloat(string $instProp, string $regProp): float
    {
        $inst = $this->ReadPropertyInteger($instProp);
        $reg  = $this->ReadPropertyInteger($regProp);
        if ($inst === 0 || $reg === 0) {
            return 0.0;
        }
        return floatval(Modbus_ReadInputFloat($inst, $reg));
    }

    private function WriteInt(string $instProp, string $regProp, int $value)
    {
        $inst = $this->ReadPropertyInteger($instProp);
        $reg  = $this->ReadPropertyInteger($regProp);
        if ($inst === 0 || $reg === 0) {
            return;
        }
        Modbus_WriteRegister($inst, $reg, $value);
    }
}