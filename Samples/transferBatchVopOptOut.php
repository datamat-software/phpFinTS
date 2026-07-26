<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUndefinedNamespaceInspection */
/** @noinspection PhpUndefinedClassInspection */

/**
 * SAMPLE - Execute a SEPA collective transfer (2 transactions) with VoP Opt-Out (HKVOO).
 *
 * Demonstrates \Fhp\BaseAction::requestVopOptOut(), which is only meaningful for batch payment orders with more
 * than one transaction (the bank rejects Opt-Out for single-transaction orders, see
 * FinTS_3.0_Messages_Geschaeftsvorfaelle_VOP_1.01_2025_06_27_FV.pdf, Section C.10.7.2).
 *
 * This example builds the pain.001 XML manually (2 <CdtTrfTxInf> blocks) instead of using the phpSepaXml library,
 * since that library does not support building a batch with more than one transaction out of the box.
 */

// See login.php, it returns a FinTs instance that is already logged in.
/** @var \Fhp\FinTs $fints */
$fints = require_once 'login.php';

// Just pick the first account, for demonstration purposes. You could also have the user choose, or have SEPAAccount
// hard-coded and not call getSEPAAccounts() at all.
$getSepaAccounts = \Fhp\Action\GetSEPAAccounts::create();
$fints->execute($getSepaAccounts);
if ($getSepaAccounts->needsTan()) {
    handleStrongAuthentication($getSepaAccounts); // See login.php for the implementation.
}
$oneAccount = $getSepaAccounts->getAccounts()[0];

$msgId = 'MSG-' . time();
$creDtTm = (new DateTime())->format('Y-m-d\TH:i:s');

$painXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.09" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <CstmrCdtTrfInitn>
    <GrpHdr>
      <MsgId>$msgId</MsgId>
      <CreDtTm>$creDtTm</CreDtTm>
      <NbOfTxs>2</NbOfTxs>
      <CtrlSum>78.56</CtrlSum>
      <InitgPty><Nm>My Company</Nm></InitgPty>
    </GrpHdr>
    <PmtInf>
      <PmtInfId>$msgId.1</PmtInfId>
      <PmtMtd>TRF</PmtMtd>
      <NbOfTxs>2</NbOfTxs>
      <CtrlSum>78.56</CtrlSum>
      <PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl></PmtTpInf>
      <ReqdExctnDt>1999-01-01</ReqdExctnDt>
      <Dbtr><Nm>My Company</Nm></Dbtr>
      <DbtrAcct><Id><IBAN>DE68210501700012345678</IBAN></Id></DbtrAcct>
      <DbtrAgt><FinInstnId><BIC>DEUTDEDB400</BIC></FinInstnId></DbtrAgt>
      <ChrgBr>SLEV</ChrgBr>
      <CdtTrfTxInf>
        <PmtId><EndToEndId>E2E-1</EndToEndId></PmtId>
        <Amt><InstdAmt Ccy="EUR">48.78</InstdAmt></Amt>
        <CdtrAgt><FinInstnId><BIC>GENODEF1P15</BIC></FinInstnId></CdtrAgt>
        <Cdtr><Nm>Max Mustermann</Nm></Cdtr>
        <CdtrAcct><Id><IBAN>CH9300762011623852957</IBAN></Id></CdtrAcct>
      </CdtTrfTxInf>
      <CdtTrfTxInf>
        <PmtId><EndToEndId>E2E-2</EndToEndId></PmtId>
        <Amt><InstdAmt Ccy="EUR">29.78</InstdAmt></Amt>
        <CdtrAgt><FinInstnId><BIC>GENODEF1P15</BIC></FinInstnId></CdtrAgt>
        <Cdtr><Nm>Erika Musterfrau</Nm></Cdtr>
        <CdtrAcct><Id><IBAN>CH2989144532982475173</IBAN></Id></CdtrAcct>
      </CdtTrfTxInf>
    </PmtInf>
  </CstmrCdtTrfInitn>
</Document>
XML;

$sendSEPATransfer = \Fhp\Action\SendSEPATransfer::create($oneAccount, $painXml);
$sendSEPATransfer->requestVopOptOut(true); // Ask the bank to skip Verification of Payee for this batch.
$fints->execute($sendSEPATransfer);

require_once 'vop.php';
handleVopAndAuthentication($sendSEPATransfer);

// SEPA transfers don't produce any result we could receive through a getter, but we still need to make sure it's done.
$sendSEPATransfer->ensureDone();
