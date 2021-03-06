<?php

namespace MadWizard\WebAuthn\Server\Registration;

use MadWizard\WebAuthn\Attestation\AuthenticatorDataInterface;
use MadWizard\WebAuthn\Attestation\TrustAnchor\MetadataInterface;
use MadWizard\WebAuthn\Attestation\Verifier\VerificationResult;
use MadWizard\WebAuthn\Credential\CredentialId;
use MadWizard\WebAuthn\Crypto\CoseKeyInterface;

interface RegistrationResultInterface
{
    /**
     * @return CredentialId
     */
    public function getCredentialId(): CredentialId;

    /**
     * @return CoseKeyInterface
     */
    public function getPublicKey(): CoseKeyInterface;

    /**
     * @return VerificationResult
     */
    public function getVerificationResult(): VerificationResult;

    /**
     * @return int
     */
    public function getSignatureCounter(): int;

    public function getAuthenticatorData(): AuthenticatorDataInterface;

    public function getMetadata(): ?MetadataInterface;
}
