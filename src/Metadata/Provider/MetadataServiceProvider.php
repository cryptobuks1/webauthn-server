<?php


namespace MadWizard\WebAuthn\Metadata\Provider;

use DateTimeImmutable;

use MadWizard\WebAuthn\Attestation\Identifier\IdentifierInterface;
use MadWizard\WebAuthn\Attestation\TrustAnchor\MetadataInterface;
use MadWizard\WebAuthn\Cache\CacheProviderInterface;
use MadWizard\WebAuthn\Exception\ParseException;
use MadWizard\WebAuthn\Exception\VerificationException;
use MadWizard\WebAuthn\Exception\WebAuthnException;
use MadWizard\WebAuthn\Format\Base64UrlEncoding;
use MadWizard\WebAuthn\Format\ByteBuffer;
use MadWizard\WebAuthn\Metadata\Source\MetadataServiceSource;
use MadWizard\WebAuthn\Metadata\Statement\MetadataStatement;
use MadWizard\WebAuthn\Metadata\Statement\MetadataToc;
use MadWizard\WebAuthn\Pki\ChainValidatorInterface;
use MadWizard\WebAuthn\Pki\Jwt\Jwt;
use MadWizard\WebAuthn\Pki\Jwt\JwtInterface;
use MadWizard\WebAuthn\Pki\Jwt\JwtValidator;
use MadWizard\WebAuthn\Pki\Jwt\ValidationContext;
use MadWizard\WebAuthn\Pki\Jwt\X5cParameterReader;
use MadWizard\WebAuthn\Pki\X509Certificate;
use MadWizard\WebAuthn\Remote\DownloaderInterface;
use MadWizard\WebAuthn\Server\Registration\RegistrationResultInterface;
use Psr\Cache\CacheItemPoolInterface;

final class MetadataServiceProvider implements MetadataProviderInterface
{
    /**
     * @var DownloaderInterface
     */
    private $downloader;

    /**
     * @var CacheItemPoolInterface
     */
    private $cachePool;

    /**
     * @var CacheProviderInterface
     */
    private $cacheProvider;

    /**
     * @var ChainValidatorInterface
     */
    private $chainValidator;

    /**
     * Cache time for specific metadata statements. Because caching of metadata statements is done by the hash of its
     * contents (which is also included in the TOC) in theory these items can be cached indefinitely because any update
     * would cause the hash in the TOC to change and trigger a new download.
     * Set to 1 day by default to prevent keeping stale data.
     */
    private const METADATA_HASH_CACHE_TTL = 86400;

    /**
     * @var MetadataServiceSource
     */
    private $mdsSource;

    public function __construct(
        MetadataServiceSource $mdsSource,
        DownloaderInterface $downloader,
        CacheProviderInterface $cacheProvider,
        ChainValidatorInterface $chainValidator
    ) {
        $this->mdsSource = $mdsSource;
        $this->cachePool = $cacheProvider->getCachePool('metadataService');
        $this->downloader = $downloader;
        $this->cacheProvider = $cacheProvider;
        $this->chainValidator = $chainValidator;
    }

    private function getTokenUrl(string $url): string
    {
        $token = $this->mdsSource->getAccessToken();
        if ($token === null) {
            return $url;
        }

        // Only add token if host matches host of main TOC URL to prevent leaking token to other hosts.
        $mainHost = parse_url($this->mdsSource->getUrl(), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);

        if (strcasecmp($mainHost, $host) === 0) {
            return $url . '?' . http_build_query(['token' => $token]);
        }
        return $url;
    }

    public function getMetadata(IdentifierInterface $identifier, RegistrationResultInterface $registrationResult): ?MetadataInterface
    {
        $toc = $this->getCachedToc();
        $tocItem = $toc->findItem($identifier);

        $str = $identifier->toString();



        if ($tocItem === null) {
            return null;
        }

        $url = $tocItem->getUrl();
        $hash = $tocItem->gethash();
        if ($url === null || $hash === null) {
            return null;
        }

        $meta = $this->getMetadataItem($url, $hash);
        if ($meta === null) {
            return null;
        }
        $meta->setStatusReports($tocItem->getStatusReports());
        return $meta;
    }

    private function getMetadataItem(string $url, ByteBuffer $hash): ?MetadataStatement    // TODO exception?
    {
        $item = $this->cachePool->getItem($hash->getHex());

        if ($item->isHit()) {
            $meta = $item->get();
        } else {
            $meta = $this->downloader->downloadFile($url); // TODO: $this->getTokenUrl($url));
            $fileHash = hash('sha256', $meta->getData(), true);
            if (!hash_equals($hash->getBinaryString(), $fileHash)) {
                error_log('Hash mismatch!');
                return null;
            }

            $item->set($meta);
            $item->expiresAfter(self::METADATA_HASH_CACHE_TTL);
            $this->cachePool->save($item);
        }
        return MetadataStatement::decodeString(Base64UrlEncoding::decode($meta->getData()));
    }

    private function getCachedToc()
    {
        $url = $this->getTokenUrl($this->mdsSource->getUrl());
        $urlHash = hash('sha256', $url);
        $item = $this->cachePool->getItem($urlHash);

        if ($item->isHit()) {
            $data = $item->get();
            if ($data instanceof MetadataToc) {
                if ($data->getNextUpdate() > new DateTimeImmutable()) {           // TODO: abstract time for unit tests
                    return $data;
                }
            }
        }

        $data = $this->downloadToc($url);

        if ($data !== null) {
            $item->set($data);
            $item->expiresAt($data->getNextUpdate());
            $this->cachePool->save($item);
        }
        return $data;
    }

    private function downloadToc($url): MetadataToc
    {
        $a = $this->downloader->downloadFile($url);
        if (!in_array(strtolower($a->getContentType()), ['application/octet-stream', 'application/jose'])) {
            throw new ParseException('Unexpected mime type.');
        }

        //error_log( md5($url) . " " . $url);
        //file_put_contents(__DIR__ . "/" . md5($url), $a->getData());
        $jwt = new Jwt($a->getData());

        $x5cParam = X5cParameterReader::getX5cParameter($jwt);
        if ($x5cParam === null) {
            throw new ParseException('MDS has no x5c certificate chain in header.');
        }


        $jwtValidator = new JwtValidator();
        $context = new ValidationContext(JwtInterface::ES_AND_RSA, $x5cParam->getCoseKey());
        try {
            $claims = $jwtValidator->validate($jwt, $context);
        } catch (WebAuthnException $e) {
            throw new VerificationException('Failed to verify JWT.', 0, $e);
        }


        if (!$this->chainValidator->validateChain(X509Certificate::fromPem($this->mdsSource->getRootCert()), ...
            array_reverse($x5cParam->getCertificates()))) {
            throw new VerificationException('Failed to verify x5c chain in JWT.');
        }
        return MetadataToc::fromJson($claims);
    }
}
