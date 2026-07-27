<?php

namespace Fhp\Segment\IPS;

use Fhp\Segment\BaseSegment;
use Fhp\Segment\Common\Kti;

/**
 * Segment: SEPA-Instant Payment Status anfordern (Version 1)
 *
 * Queries the final settlement status of a previously submitted SEPA instant payment ({@link \Fhp\Segment\IPZ\HKIPZv1}).
 * The bank asks for this via Rueckmeldung 3045; per the specification, client-side support is mandatory ("Realisierung
 * Kunde: verpflichtend") once the SEPA instant payment transaction itself is supported.
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Messages_Geschaeftsvorfaelle_2022-04-15_final_version.pdf
 * Section: C.10.2.9.2 a)
 */
// Generiert mit Claude Opus 4.8
class HKIPSv1 extends BaseSegment
{
    public Kti $kontoverbindungInternational;
    public UnterstuetzteSEPAPainMessages $unterstuetzteSEPAPainMessages;
    /** Max length: 99. The Auftragsidentifikation the bank returned in HIIPZ for the original payment. */
    public string $auftragsidentifikation;

    public static function create(Kti $kti, UnterstuetzteSEPAPainMessages $unterstuetzteSEPAPainMessages, string $auftragsidentifikation): HKIPSv1
    {
        $result = HKIPSv1::createEmpty();
        $result->kontoverbindungInternational = $kti;
        $result->unterstuetzteSEPAPainMessages = $unterstuetzteSEPAPainMessages;
        $result->auftragsidentifikation = $auftragsidentifikation;
        return $result;
    }
}
