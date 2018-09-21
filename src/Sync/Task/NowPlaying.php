<?php
namespace App\Sync\Task;

use App\Cache;
use App\Event\GenerateRawNowPlaying;
use App\Event\SendWebhooks;
use App\EventDispatcher;
use App\Radio\AutoDJ;
use App\ApiUtilities;
use App\Radio\Adapters;
use Doctrine\ORM\EntityManager;
use InfluxDB\Database;
use App\Entity;
use Monolog\Logger;

class NowPlaying extends TaskAbstract
{
    /** @var EntityManager */
    protected $em;

    /** @var Database */
    protected $influx;

    /** @var Cache */
    protected $cache;

    /** @var Adapters */
    protected $adapters;

    /** @var AutoDJ */
    protected $autodj;

    /** @var Logger */
    protected $logger;

    /** @var EventDispatcher */
    protected $event_dispatcher;

    /** @var ApiUtilities */
    protected $api_utils;

    /** @var Entity\Repository\SongHistoryRepository */
    protected $history_repo;

    /** @var Entity\Repository\SongRepository */
    protected $song_repo;

    /** @var Entity\Repository\ListenerRepository */
    protected $listener_repo;

    /** @var Entity\Repository\SettingsRepository */
    protected $settings_repo;

    /** @var string */
    protected $analytics_level;

    /**
     * @param Adapters $adapters
     * @param ApiUtilities $api_utils
     * @param AutoDJ $autodj
     * @param Cache $cache
     * @param Database $influx
     * @param EntityManager $em
     * @param EventDispatcher $event_dispatcher
     * @param Logger $logger
     *
     * @see \App\Provider\SyncProvider
     */
    public function __construct(
        Adapters $adapters,
        ApiUtilities $api_utils,
        AutoDJ $autodj,
        Cache $cache,
        Database $influx,
        EntityManager $em,
        EventDispatcher $event_dispatcher,
        Logger $logger)
    {
        $this->adapters = $adapters;
        $this->api_utils = $api_utils;
        $this->autodj = $autodj;
        $this->cache = $cache;
        $this->em = $em;
        $this->event_dispatcher = $event_dispatcher;
        $this->influx = $influx;
        $this->logger = $logger;

        $this->history_repo = $this->em->getRepository(Entity\SongHistory::class);
        $this->song_repo = $this->em->getRepository(Entity\Song::class);
        $this->listener_repo = $this->em->getRepository(Entity\Listener::class);

        $this->settings_repo = $this->em->getRepository(Entity\Settings::class);
        $this->analytics_level = $this->settings_repo->getSetting('analytics', Entity\Analytics::LEVEL_ALL);
    }

    public function run($force = false)
    {
        $nowplaying = $this->_loadNowPlaying($force);

        // Post statistics to InfluxDB.
        if ($this->analytics_level !== Entity\Analytics::LEVEL_NONE) {
            $influx_points = [];

            $total_overall = 0;

            foreach ($nowplaying as $info) {
                $listeners = (int)$info->listeners->current;
                $total_overall += $listeners;

                $station_id = $info->station->id;

                $influx_points[] = new \InfluxDB\Point(
                    'station.' . $station_id . '.listeners',
                    $listeners,
                    [],
                    ['station' => $station_id],
                    time()
                );
            }

            $influx_points[] = new \InfluxDB\Point(
                'station.all.listeners',
                $total_overall,
                [],
                ['station' => 0],
                time()
            );

            $this->influx->writePoints($influx_points, \InfluxDB\Database::PRECISION_SECONDS);
        }

        // Generate API cache.
        foreach ($nowplaying as $station => $np_info) {
            $nowplaying[$station]->cache = 'hit';
        }

        $this->cache->save($nowplaying, 'api_nowplaying_data', 120);

        foreach ($nowplaying as $station => $np_info) {
            $nowplaying[$station]->cache = 'database';
        }

        /** @var Entity\Repository\SettingsRepository $settings_repo */
        $settings_repo = $this->em->getRepository(Entity\Settings::class);

        $settings_repo->setSetting('nowplaying', $nowplaying);
    }

    /**
     * @return Entity\Api\NowPlaying[]
     */
    protected function _loadNowPlaying($force = false)
    {
        $stations = $this->em->getRepository(Entity\Station::class)
            ->findBy(['is_enabled' => 1]);
        $nowplaying = [];

        foreach ($stations as $station) {
            /** @var Entity\Station $station */
            $last_run = $station->getNowplayingTimestamp();

            if ($last_run >= (time()-10) && !$force) {
                $np = $station->getNowplaying();
                $np->update();

                $nowplaying[] = $np;
            } else {
                $nowplaying[] = $this->processStation($station);
            }
        }

        return $nowplaying;
    }

    /**
     * Generate Structured NowPlaying Data for a given station.
     *
     * @param Entity\Station $station
     * @param string|null $payload The request body from the watcher notification service (if applicable).
     * @return Entity\Api\NowPlaying
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Exception
     */
    public function processStation(Entity\Station $station, $payload = null)
    {
        $this->logger->pushProcessor(function($record) use ($station) {
            $record['extra']['station'] = [
                'id' => $station->getId(),
                'name' => $station->getName(),
            ];
            return $record;
        });

        $include_clients = ($this->analytics_level === Entity\Analytics::LEVEL_ALL);

        $frontend_adapter = $this->adapters->getFrontendAdapter($station);
        $remote_adapters = $this->adapters->getRemoteAdapters($station);

        /** @var Entity\Api\NowPlaying|null $np_old */
        $np_old = $station->getNowplaying();

        // Build the new "raw" NowPlaying data.
        $event = new GenerateRawNowPlaying($station, $frontend_adapter, $remote_adapters, $payload, $include_clients);
        $this->event_dispatcher->dispatch(GenerateRawNowPlaying::NAME, $event);
        $np_raw = $event->getRawResponse();

        $np = new Entity\Api\NowPlaying;
        $np->station = $station->api($frontend_adapter, $remote_adapters);
        $np->listeners = new Entity\Api\NowPlayingListeners($np_raw['listeners']);

        if (empty($np_raw['current_song']['text'])) {
            $song_obj = $this->song_repo->getOrCreate(['text' => 'Stream Offline'], true);

            $offline_sh = new Entity\Api\NowPlayingCurrentSong;
            $offline_sh->sh_id = 0;
            $offline_sh->song = $song_obj->api($this->api_utils);
            $np->now_playing = $offline_sh;

            $np->song_history = $this->history_repo->getHistoryForStation($station, $this->api_utils);

            $next_song = $this->autodj->getNextSong($station);
            if ($next_song instanceof Entity\SongHistory) {
                $np->playing_next = $next_song->api(new Entity\Api\SongHistory, $this->api_utils);
            } else {
                $np->playing_next = null;
            }

            $np->live = new Entity\Api\NowPlayingLive(false);
        } else {
            // Pull from current NP data if song details haven't changed.
            $current_song_hash = Entity\Song::getSongHash($np_raw['current_song']);

            if ($np_old instanceof Entity\Api\NowPlaying && strcmp($current_song_hash, $np_old->now_playing->song->id) === 0) {
                /** @var Entity\Song $song_obj */
                $song_obj = $this->song_repo->find($current_song_hash);

                $sh_obj = $this->history_repo->register($song_obj, $station, $np_raw);

                $np->song_history = $np_old->song_history;
                $np->playing_next = $np_old->playing_next;
            } else {
                // SongHistory registration must ALWAYS come before the history/nextsong calls
                // otherwise they will not have up-to-date database info!
                $song_obj = $this->song_repo->getOrCreate($np_raw['current_song'], true);
                $sh_obj = $this->history_repo->register($song_obj, $station, $np_raw);

                $np->song_history = $this->history_repo->getHistoryForStation($station, $this->api_utils);

                $next_song = $this->autodj->getNextSong($station);

                if ($next_song instanceof Entity\SongHistory) {
                    $np->playing_next = $next_song->api(new Entity\Api\SongHistory, $this->api_utils);
                }
            }

            // Update detailed listener statistics, if they exist for the station
            if ($include_clients && isset($np_raw['listeners']['clients'])) {
                $this->listener_repo->update($station, $np_raw['listeners']['clients']);
            }

            // Detect and report live DJ status
            if ($station->getIsStreamerLive()) {
                $current_streamer = $station->getCurrentStreamer();
                $streamer_name = ($current_streamer instanceof Entity\StationStreamer)
                    ? $current_streamer->getDisplayName()
                    : 'Live DJ';

                $np->live = new Entity\Api\NowPlayingLive(true, $streamer_name);
            } else {
                $np->live = new Entity\Api\NowPlayingLive(false, '');
            }

            // Register a new item in song history.
            $np->now_playing = $sh_obj->api(new Entity\Api\NowPlayingCurrentSong, $this->api_utils);
        }

        $np->update();
        $np->cache = 'station';

        $station->setNowplaying($np);

        $this->em->persist($station);
        $this->em->flush();

        // Trigger the dispatching of webhooks.
        $webhook_event = new SendWebhooks($station, $np, $np_old, ($payload !== null));
        $this->event_dispatcher->dispatch(SendWebhooks::NAME, $webhook_event);

        $this->logger->popProcessor();

        return $np;
    }
}
