<?php


namespace App;

use Bolt\Common\Json;
use Bolt\Common\Str;
use Bolt\Configuration\Content\ContentType;
use Bolt\Entity\Content;
use Bolt\Enum\Statuses;
use Bolt\Extension\BaseExtension;
use Bolt\Repository\ContentRepository;
use Bolt\Storage\Query;
use Symfony\Component\HttpClient\HttpClient;
use Tightenco\Collect\Support\Collection;

class PackagistExtension extends BaseExtension
{
    private const PACKAGIST_LIST = 'https://packagist.org/packages/list.json';
    private const PACKAGIST_DETAIL = 'https://packagist.org/packages/';
    private const TYPE_EXTENSION = 'bolt-extension';
    private const TYPE_THEME = 'bolt-theme';

    /** @var ContentRepository */
    private $contentRepository;

    public function getName(): string
    {
        return "Packagist API fetcher";
    }

    public function initialize(): void
    {

    }

    public function fetchPackages(string $type): void
    {
        if ($type == 'extension') {
            $type = self::TYPE_EXTENSION;
        } else {
            $type = self::TYPE_THEME;
        }

        $url = sprintf('%s?type=%s', self::PACKAGIST_LIST, $type);
        $client = HttpClient::create();
        $response = $client->request('GET', $url);

        $json = $response->getContent();
        $packages = Json::json_decode($json);

        $om = $this->getObjectManager();
        /** @var ContentRepository $contentRepository */
        $contentRepository = $om->getRepository(Content::class);

        foreach ($packages->packageNames as $package) {
            $record = $contentRepository->findOneByFieldValue('packagist_name', $package);

            if (!$record) {
                echo "Add new stub: $package \n";
                $this->insertPackageStub($package, self::TYPE_EXTENSION);
            }
        }
    }


    private function insertPackageStub(string $package, string $type)
    {
        $om = $this->getObjectManager();

        $contentTypeDefinition = $this->getBoltConfig()->getContentType('packages');
        $content = new Content($contentTypeDefinition);

        $content->setFieldValue('packagist_name', $package);
        $content->setFieldValue('title', $package);
        $content->setFieldValue('slug', $package);
        $content->setFieldValue('packagist_type', $type);
        $content->setModifiedAt(new \DateTime('last year'));

        $om->persist($content);
        $om->flush();
    }

    public function updatePackages()
    {
        $om = $this->getObjectManager();


        $client = HttpClient::create();

        $params = ['order' => 'modifiedAt', 'status' => '!unknown', 'packagist_type' => 'bolt-theme'];

        $records = $this->getQuery()->getContentForTwig('packages', $params);

        $count = 0;

        /** @var Content $record */
        foreach ($records as $record) {
            if ($count++ >= 3) {
                break;
            }

            $packagistName = (string) $record->getFieldValue('packagist_name');
            dump($packagistName);

            $url = sprintf('%s%s.json', self::PACKAGIST_DETAIL, $packagistName);

            $response = $client->request('GET', $url);
            $responseArray = current($response->toArray());

            $this->updateRecord($record, $responseArray, $packagistName);

            $om->persist($record);
        }

        $om->flush();
    }

    private function updateRecord(Content $record, array $responseArray, string $packagistName): void
    {
        $package = new Collection($responseArray);

        $record->setFieldValue('description', $package->get('description'));
        $record->setFieldValue('time', $package->get('time'));
        $record->setFieldValue('maintainers', $package->get('maintainers'));
        $record->setFieldValue('packagist_type', $package->get('type'));
        $record->setFieldValue('repository', $package->get('repository'));
        $record->setFieldValue('github_stars', $package->get('github_stars'));
        $record->setFieldValue('downloads_total', $package->get('downloads')['total']);
        $record->setFieldValue('downloads_monthly', $package->get('downloads')['monthly']);
        $record->setFieldValue('downloads_daily', $package->get('downloads')['daily']);
        $record->setFieldValue('favers', $package->get('favers'));

        $record->setModifiedAt(new \DateTime());

        $versions = $this->sortVersions($package);

        $record->setFieldValue('versions', $versions);

        $latest_version = (new Collection($package->get('versions')))->get(current($versions));

        if (isset($latest_version['require']['bolt/core'])) {
            $record->setFieldValue('required_version', $latest_version['require']['bolt/core']);
            $record->setFieldValue('require', $latest_version['require']);
        } else {
            $record->setFieldValue('required_version', 3);
        }

        if (isset($latest_version['extra']['screenshots'])) {
            $record->setFieldValue('screenshots', $latest_version['extra']['screenshots']);
        } else {
            $record->setFieldValue('screenshots', []);
        }

        $record->setStatus(Statuses::PUBLISHED);

        if ($packagistName == 'bolt/themes') {
            dump($latest_version);
        }

        // @todo This is hackish. Make better.
        if (in_array($packagistName, [
            "bolt/bolt-extension-starter",
            "bolt/bolt-extension-starter-extended",
            "rixbeck/bolt-extension-skeleton",
            "wemakecustom/bolt-parent-theme",
            "bolt/htmlsection",
            "eamador/bolt-dialog-pages",
            "ggioffreda/bolt-extension-rollbar",
            "gigabit/twig-wrap",
            "goodbytes/readtime",
            "mattvick/bolt-diy-forms",
            "ornito/rest-create-users",
            "zillingen/json-content",
            "zillingen/json-files",
        ])) {
            $record->setStatus(Statuses::DRAFT);
        }
    }

    private function sortVersions(Collection $package): array
    {
        $rawVersions = (new Collection($package->get('versions')))->keys()->all();
        $versions = [];

        foreach ($rawVersions as $version) {
            $key = $version;
            if (Str::startsWith($key, 'v')) {
                $key = Str::removeFirst($key, 'v');
            }
            $versions[$key] = $version;
        }

        uksort($versions, 'version_compare');

        return array_reverse($versions);
    }
}