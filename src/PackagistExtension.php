<?php


namespace App;

use Bolt\Common\Json;
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

    public function fetchPackages()
    {
        $url = sprintf('%s?type=%s', self::PACKAGIST_LIST, self::TYPE_EXTENSION);
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

        $om->persist($content);
        $om->flush();
    }

    public function updatePackages()
    {
        $om = $this->getObjectManager();


        $client = HttpClient::create();

        $params = ['order' => 'modifiedAt', 'status' => '!unknown'];

        $records = $this->getQuery()->getContentForTwig('packages', $params);

        $count = 0;

        /** @var Content $record */
        foreach ($records as $record) {
            if ($count++ >= 100) {
                break;
            }

            $packagist_name = (string) $record->getFieldValue('packagist_name');
            dump($packagist_name);

            $url = sprintf('%s%s.json', self::PACKAGIST_DETAIL, $packagist_name);

            $response = $client->request('GET', $url);
            $response_array = current($response->toArray());

            $package = new Collection($response_array);

            if ($response_array) {
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

                $om->persist($record);

                $versions = (new Collection($package->get('versions')))->keys()->sort(function ($version, $key) {
                    return strpos($version, 'ev-');
                })->all();

                $record->setFieldValue('versions', $versions);

                $latest_version = (new Collection($package->get('versions')))->get(current($versions));

                if (isset($latest_version['require']['bolt/core'])) {
                    $record->setFieldValue('required_version', $latest_version['require']['bolt/core']);
                } else {
                    $record->setFieldValue('required_version', 3);
                }

                $record->setStatus(Statuses::PUBLISHED);

                // @todo This is hackish. Make better.
                if (in_array($packagist_name, [

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

            $om->persist($record);
        }

        $om->flush();

    }
}