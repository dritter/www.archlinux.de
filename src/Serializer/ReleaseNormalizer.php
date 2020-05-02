<?php

namespace App\Serializer;

use App\Entity\Release;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\CacheableSupportsMethodInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class ReleaseNormalizer implements NormalizerInterface, CacheableSupportsMethodInterface
{
    /** @var UrlGeneratorInterface */
    private $router;

    /** @var ObjectNormalizer */
    private $normalizer;

    /**
     * @param UrlGeneratorInterface $router
     * @param ObjectNormalizer $normalizer
     */
    public function __construct(UrlGeneratorInterface $router, ObjectNormalizer $normalizer)
    {
        $this->router = $router;
        $this->normalizer = $normalizer;
    }

    /**
     * @inheritDoc
     */
    public function supportsNormalization($data, string $format = null)
    {
        return $data instanceof Release;
    }

    /**
     * @param Release $object
     * @param string $format
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public function normalize($object, string $format = null, array $context = [])
    {
        /** @var array<mixed> $data */
        $data = $this->normalizer->normalize(
            $object,
            $format,
            array_merge(
                $context,
                [
                    AbstractNormalizer::ATTRIBUTES => [
                        'version',
                        'available',
                        'info',
                        'isoUrl',
                        'kernelVersion',
                        'releaseDate',
                        'sha1Sum'
                    ]
                ]
            )
        );

        $data['torrentUrl'] = $object->getTorrent()->getUrl()
            ? 'https://www.archlinux.org' . $object->getTorrent()->getUrl()
            : null;
        $data['fileSize'] = $object->getTorrent()->getFileLength();
        $data['magnetUri'] = $object->getTorrent()->getMagnetUri();
        $data['isoPath'] = $data['isoUrl'];
        $data['isoUrl'] = $data['available'] ? $this->router->generate(
            'app_mirror_iso',
            [
                'file' => $object->getTorrent()->getFileName(),
                'version' => $object->getVersion()
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        ) : null;
        $data['isoSigUrl'] = 'https://www.archlinux.org' . $data['isoPath'] . '.sig';
        $data['fileName'] = $object->getTorrent()->getFileName();

        return $data;
    }

    /**
     * @return bool
     */
    public function hasCacheableSupportsMethod(): bool
    {
        return true;
    }
}
