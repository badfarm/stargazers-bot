<?php

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Zanzara\Config;
use Zanzara\Zanzara;
use Zanzara\Context;
use Goutte\Client;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// define a logger
$logger = new Logger("stars");
$streamHandler = new StreamHandler(__DIR__ . '/../logs/app.log', \Monolog\Logger::DEBUG);
$logger->pushHandler($streamHandler);

$config = new Config();
$config->setLogger($logger);
$bot = new Zanzara($_ENV['BOT_TOKEN'], $config);


$bot->setGlobalData("reposToCheck", array());
periodicCheck($bot);

$bot->onCommand('start', function (Context $ctx) use ($bot) {
    $ctx->sendMessage('Hi give me the url of the stargazer repo page');
    $ctx->nextStep("startParser");
});


function getStars($url)
{
    try {
        $client = new Client();
        $crawler = $client->request('GET', $url);
        return $crawler->filterXPath("//*[@id=\"repos\"]/div[1]/nav/a[1]/span")->text();
    } catch (Exception $e) {
        return false;
    }

}


function periodicCheck(Zanzara $bot)
{
    $loop = $bot->getLoop();
    $loop->addPeriodicTimer(5, function ($timer) use ($loop, $bot) {
        $bot->getGlobalDataItem("reposToCheck")->then(function ($reposToCheck) use ($bot) {
            $edited = false;
            foreach ($reposToCheck as $key => $repo) {
                $newStars = getStars($repo["url"]);
                if ($newStars != $repo["stars"]) {
                    $reposToCheck[$key]["stars"] = $newStars;
                    $edited = true;
                    foreach ($repo["watchers"] as $watcher) {
                        if ($newStars == strval(100)) {
                            $bot->getTelegram()->sendMessage("Bisogna offrire a strina cazzo", ["chat_id" => $watcher]);
                        } else {
                            $bot->getTelegram()->sendMessage("new stars: " . $newStars, ["chat_id" => $watcher]);
                        }
                    }

                }
            }

            if ($edited) {
                $bot->setGlobalData("reposToCheck", $reposToCheck);
            }
        });
    });
}


function startParser(Context $ctx)
{
    global $bot;
    $newUrl = $ctx->getMessage()->getText();
    $newStars = getStars($newUrl);
    $chatId = $ctx->getEffectiveUser()->getId();
    if ($newStars) {
        $ctx->sendMessage("Starting stars: " . $newStars);

        $ctx->getGlobalDataItem("reposToCheck")->then(function ($reposToCheck) use ($newUrl, $newStars, $chatId, $ctx, $bot) {

            $founded = false;

            foreach ($reposToCheck as $key => $repo) {
                if ($repo["url"] == $newUrl) {
                    $reposToCheck[$key]["stars"] = $newStars;
                    if (!in_array($chatId, $repo["watchers"])) {
                        array_push($reposToCheck[$key]["watchers"], $chatId);
                    }
                    $founded = true;
                    break;
                }
            }

            if (!$founded) {
                $newItem = [
                    "url" => $newUrl,
                    "stars" => $newStars,
                    "watchers" => [$chatId]

                ];
                array_push($reposToCheck, $newItem);
            }

            $ctx->setGlobalData("reposToCheck", $reposToCheck)->then(function ($result) use ($ctx) {
                if ($result) {
                    $ctx->sendMessage("Correctly set in watching");
                } else {
                    $ctx->sendMessage("something went wrong");
                }
            });
        });
    } else {
        $ctx->sendMessage("can't find stars on page, retry with the stargazer page");
    }
}

$bot->run();