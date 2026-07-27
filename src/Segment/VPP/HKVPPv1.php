<?php

namespace Fhp\Segment\VPP;

use Fhp\Segment\BaseSegment;
use Fhp\Syntax\Bin;

/**
 * Segment: Namensabgleich Prüfauftrag
 *
 * @see FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf
 * Section: C.10.7.1 a)
 */
class HKVPPv1 extends BaseSegment
{
    public UnterstuetztePaymentStatusReportsV1 $unterstuetztePaymentStatusReports;

    public ?Bin $pollingId = null;

    /**
     * Only allowed if {@link ParameterNamensabgleichPruefauftragV1::$eingabeAnzahlEintraegeErlaubt} says so - but even
     * banks that report "J" there reject the field in the HKVPP that accompanies the payment order (observed with
     * Atruvia/GAD, which answers 9010 "Der Parameter 'maxNoEntries' ist nicht erlaubt" + 9210 "VOP-Auftrag ungültig",
     * killing the order for good via 3945). It is therefore never populated; the field itself must stay declared,
     * because it holds wire position 3 ahead of $aufsetzpunkt. See VopHelper::createHKVPPForInitialRequest().
     */
    // Generiert mit Claude Opus 4.8
    public ?int $maximaleAnzahlEintraege = null;

    /** For pagination. Max length: 35 */
    public ?string $aufsetzpunkt = null;

    public static function createEmpty(): static
    {
        $hkvpp = parent::createEmpty();
        $hkvpp->unterstuetztePaymentStatusReports = new UnterstuetztePaymentStatusReportsV1();
        return $hkvpp;
    }
}
