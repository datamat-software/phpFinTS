<?php

namespace Fhp\Segment\IPS;

use Fhp\Segment\BaseSegment;
use Fhp\Segment\Common\Kti;
use Fhp\Syntax\Bin;

/**
 * Segment: SEPA-Instant Payment Status rückmelden (Version 1)
 *
 * Response to {@link HKIPSv1}. Note the field order: the two optional pain fields sit *before* the mandatory
 * Auftragsidentifikation, so they occupy their wire positions even when empty.
 *
 * @link https://www.hbci-zka.de/dokumente/spezifikation_deutsch/fintsv3/FinTS_3.0_Messages_Geschaeftsvorfaelle_2022-04-15_final_version.pdf
 * Section: C.10.2.9.2 b)
 */
// Generiert mit Claude Opus 4.8
class HIIPSv1 extends BaseSegment
{
    public Kti $kontoverbindungInternational;

    /** Max length: 256 */
    public ?string $sepaDescriptor = null;

    /** "SEPA Überweisung Kunde-Bank"-Schema per HIIPSS resp. HISPAS. */
    public ?Bin $sepaPainMessage = null;

    /** Max length: 99 */
    public string $auftragsidentifikation;

    /**
     * SEPA C-Code ("C" for Cancellation)
     * Possible values
     * 1, 2
     * 3: Delete
     * 4: Recall
     */
    public ?int $sepaCCode = null;

    /*
     * Possible values
     * 1: in Terminierung
     * 2: abgelehnt von erster Inkassostelle
     * 3: in Bearbeitung
     * 4: Creditoren-seitig verarbeitet, Buchung veranlasst
     * 5: R-Transaktion wurde veranlasst
     * 6: Auftrag fehlgeschagen
     * 7: Auftrag ausgeführt; Geld für den Zahlungsempfänger verfügbar
     * 8: Abgelehnt durch Zahlungsdienstleister des Zahlers
     * 9: Abgelehnt durch Zahlungsdienstleister des Zahlungsempfängers
     */
    public ?int $statusSepaAuftrag = null;
}
