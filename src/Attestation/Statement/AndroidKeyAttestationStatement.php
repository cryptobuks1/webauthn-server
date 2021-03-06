<?php


namespace MadWizard\WebAuthn\Attestation\Statement;

use MadWizard\WebAuthn\Attestation\AttestationObjectInterface;
use MadWizard\WebAuthn\Attestation\Registry\AttestationFormatInterface;
use MadWizard\WebAuthn\Attestation\Registry\BuiltInAttestationFormat;
use MadWizard\WebAuthn\Attestation\Verifier\AndroidKeyAttestationVerifier;
use MadWizard\WebAuthn\Dom\CoseAlgorithm;
use MadWizard\WebAuthn\Exception\DataValidationException;
use MadWizard\WebAuthn\Exception\ParseException;
use MadWizard\WebAuthn\Format\ByteBuffer;
use MadWizard\WebAuthn\Format\DataValidator;

class AndroidKeyAttestationStatement extends AbstractAttestationStatement
{
    public const FORMAT_ID = 'android-key';

    /**
     * @var ByteBuffer
     */
    private $signature;

    /**
     * @var string[]
     */
    private $certificates;

    /**
     * @see CoseAlgorithm enumeration
     * @var int
     */
    private $algorithm;

    public function __construct(AttestationObjectInterface $attestationObject)
    {
        parent::__construct($attestationObject, self::FORMAT_ID);

        $statement = $attestationObject->getStatement();

        try {
            DataValidator::checkTypes(
                $statement,
                [
                    'alg' => 'integer',
                    'x5c' => 'array',
                    'sig' => ByteBuffer::class,
                ]
            );
        } catch (DataValidationException $e) {
            throw new ParseException('Invalid Android key attestation statement.', 0, $e);
        }

        $this->signature = $statement['sig'];
        $this->algorithm = $statement['alg'];
        $this->certificates = $this->buildPEMCertificateArray($statement['x5c']);
    }

    /**
     * @return int
     */
    public function getAlgorithm(): int
    {
        return $this->algorithm;
    }

    /**
     * @return ByteBuffer
     */
    public function getSignature(): ByteBuffer
    {
        return $this->signature;
    }

    /**
     * @return string[]
     */
    public function getCertificates(): array
    {
        return $this->certificates;
    }

    public static function createFormat() : AttestationFormatInterface
    {
        return new BuiltInAttestationFormat(
            self::FORMAT_ID,
            self::class,
            AndroidKeyAttestationVerifier::class
        );
    }
}
