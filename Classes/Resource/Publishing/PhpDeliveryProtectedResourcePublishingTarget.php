<?php
declare(strict_types=1);
namespace Bitmotion\SecureDownloads\Resource\Publishing;

/***
 *
 * This file is part of the "Secure Downloads" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2019 Florian Wessels <f.wessels@bitmotion.de>, Bitmotion GmbH
 *
 ***/

use Bitmotion\SecureDownloads\Parser\HtmlParser;
use Firebase\JWT\JWT;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\ResourceInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PhpDeliveryProtectedResourcePublishingTarget extends AbstractResourcePublishingTarget
{
    /**
     * @deprecated Will be removed in version 5.
     */
    const DEFAULT_LINK_FORMAT = 'index.php?eID=tx_securedownloads&p=###PAGE###&u=###FEUSER###&g=###FEGROUPS###&t=###TIMEOUT###&hash=###HASH###&file=###FILE###';

    const DEFAULT_CACHE_LIFETIME = 86400;

    /**
     * @deprecated Will be removed in version 5.
     */
    const ALLOWED_TOKENS = [
        '###FEUSER###',
        '###FEGROUPS###',
        '###FILE###',
        '###TIMEOUT###',
        '###HASH###',
        '###PAGE###',
    ];

    protected $cache = [];

    /**
     * Publishes a persistent resource to the web accessible resources directory
     *
     * @param ResourceInterface $resource The resource to publish
     *
     * @return mixed Either the web URI of the published resource or FALSE if the resource source file doesn't exist or
     *     the resource could not be published for other reasons
     *
     * @deprecated Will be removed in version 5.
     */
    public function publishResource(ResourceInterface $resource)
    {
        trigger_error('Method publishResource() will be removed in version 5.', E_USER_DEPRECATED);

        $publicUrl = false;
        // We only manipulate the URL if we are in the backend or in FAL mode in FE (otherwise we parse the HTML)
        if (!$this->getRequestContext()->isFrontendRequest()) {
            $this->setResourcesSourcePath($this->getResourcesSourcePathByResourceStorage($resource->getStorage()));
            if ($this->isSourcePathInDocumentRoot()) {
                // We need to use absolute paths then or copy the files around, or...
                if (!$this->isPubliclyAvailable($resource)) {
                    $publicUrl = $this->buildUri($this->getResourceUri($resource));
                }
            }
        }

        return $publicUrl;
    }

    /**
     * Checks if a resource which lies in document root is really publicly available
     * This is currently only done by checking configured secure paths, not by requesting the resources
     *
     * @deprecated Will be removed in version 5.
     */
    protected function isPubliclyAvailable(ResourceInterface $resource): bool
    {
        trigger_error('Method isPubliclyAvailable() will be removed in version 5.', E_USER_DEPRECATED);

        $resourceUri = $this->getResourceUri($resource);
        $securedFoldersExpression = $this->extensionConfiguration->getSecuredDirs();
        $securedFileTypes = $this->extensionConfiguration->getSecuredFileTypes();

        if (substr($securedFileTypes, 0, 1) === '\\') {
            $fileExtensionExpression = $securedFileTypes;
        } else {
            $fileExtensionExpression = '\\.(' . $securedFileTypes . ')';
        }

        // TODO: maybe check if the resource is available without authentication by doing a head request
        return !(preg_match(
            '/((' . HtmlParser::softQuoteExpression($securedFoldersExpression) . ')+?\/.*?(?:(?i)' . ($fileExtensionExpression) . '))/i',
                $resourceUri,
            $matchedUrls
        ) && is_array($matchedUrls) && $matchedUrls[0] === $resourceUri);
    }

    /**
     * Builds a URI which uses a PHP Script to access the resource
     * by taking several parameters into account
     */
    protected function buildUri(string $resourceUri): string
    {
        $userId = $this->getRequestContext()->getUserId();
        $userGroupIds = $this->getRequestContext()->getUserGroupIds();

        if ($this->extensionConfiguration->isLegacyDelivery()) {
            return $this->getUrlWithParameters($resourceUri, $userId, $userGroupIds);
        }

        return $this->getUrlWithJWT($userId, $userGroupIds, $resourceUri);
    }

    /**
     * @deprecated Will be removed in version 5. You should consider to use Json Web Tokens for URL generation
     */
    private function getUrlWithParameters(string $resourceUri, int $user, array $userGroups): string
    {
        trigger_error('Method getUrlWithParameters() will be removed in version 5.', E_USER_DEPRECATED);

        $validityPeriod = $this->calculateLinkLifetime();
        $hash = $this->getHash($resourceUri, $user, $userGroups, $validityPeriod);

        // Parsing the link format, and return this instead (an flexible link format is useful for mod_rewrite tricks ;)
        $configuredLinkFormat = $this->extensionConfiguration->getLinkFormat();
        $linkFormat = !empty($configuredLinkFormat) ? $configuredLinkFormat : self::DEFAULT_LINK_FORMAT;

        $replacements = [
            $user,
            rawurlencode(implode(',', $userGroups)),
            str_replace('%2F', '/', rawurlencode($resourceUri)),
            $validityPeriod,
            $hash,
            $GLOBALS['TSFE']->id,
        ];

        return str_replace(self::ALLOWED_TOKENS, $replacements, $linkFormat);
    }

    private function getUrlWithJWT(int $user, array $userGroups, string $resourceUri): string
    {
        $hash = md5($user . $userGroups . $resourceUri . $GLOBALS['TSFE']->id);

        // Retrive URL from JWT cache
        if (isset($this->cache[$hash])) {
            return $this->cache[$hash];
        }

        $payload = [
            'iat' => time(),
            'exp' => $this->calculateLinkLifetime(),
            'user' => $user,
            'groups' => $userGroups,
            'file' => $resourceUri,
            'page' => $GLOBALS['TSFE']->id,
        ];

        $url = sprintf(
            '%s/%s%s/%s',
            $this->extensionConfiguration->getLinkPrefix(),
            $this->extensionConfiguration->getTokenPrefix(),
            JWT::encode($payload, $this->getRequestContext()->getAdditionalSecret(), 'HS256'),
            pathinfo($resourceUri, PATHINFO_BASENAME)
        );

        // Store URL in JWT cache
        $this->cache[$hash] = $url;

        return $url;
    }

    protected function calculateLinkLifetime(): int
    {
        $lifeTimeToAdd = $this->extensionConfiguration->getCacheTimeAdd();
        $requestCacheLifetime = $this->getRequestContext()->getCacheLifetime();
        $cacheLifetime = $requestCacheLifetime > 0 ? $requestCacheLifetime : self::DEFAULT_CACHE_LIFETIME;

        return $cacheLifetime + $GLOBALS['EXEC_TIME'] + $lifeTimeToAdd;
    }

    /**
     * @deprecated Will be removed in version 5.
     */
    protected function getHash(string $resourceUri, int $userId, array $userGroupIds, int $validityPeriod): string
    {
        trigger_error('Method getHash() will be removed in version 5.', E_USER_DEPRECATED);

        if ($this->extensionConfiguration->isEnableGroupCheck()) {
            $hashString = $userId . implode(',', $userGroupIds) . $resourceUri . $validityPeriod;
        } else {
            $hashString = $userId . $resourceUri . $validityPeriod;
        }

        return GeneralUtility::hmac($hashString, 'bitmotion_securedownload');
    }

    /**
     * Builds a URI which uses a PHP Script to access the resource
     */
    public function publishResourceUri(string $resourceUri): string
    {
        $this->setResourcesSourcePath(Environment::getPublicPath() . '/');

        return $this->buildUri($resourceUri);
    }
}
