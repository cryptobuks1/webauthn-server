<?php


namespace MadWizard\WebAuthn\Dom;

class CredentialCreationOptions extends AbstractDictionary
{
    /**
     * @var PublicKeyCredentialCreationOptions|null
     */
    private $publicKey;

    public function __construct()
    {
    }

    public function setPublicKeyOptions(PublicKeyCredentialCreationOptions $options)
    {
        $this->publicKey = $options;
    }

    /**
     * @return PublicKeyCredentialCreationOptions|null
     */
    public function getPublicKeyOptions(): ?PublicKeyCredentialCreationOptions
    {
        return $this->publicKey;
    }

    public function getAsArray(): array
    {
        $map = [];
        if ($this->publicKey !== null) {
            $map['publicKey'] = $this->publicKey;
        }
        return $map;
    }
}
