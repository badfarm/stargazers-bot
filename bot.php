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
$streamHandler = new StreamHandler(__DIR__ . '/logs/app.log', Logger::DEBUG);
$logger->pushHandler($streamHandler);

//zanzara configuration
$config = new Config();
$config->setCacheTtl(null); //persistent
$config->setLogger($logger);
$config->setParseMode(Config::PARSE_MODE_HTML);

$bot = new Zanzara($_ENV['BOT_TOKEN'], $config);


//init in cache an array named reposToCheck. I use it later for save repositories sent by the users
$bot->setGlobalData("reposToCheck", array());
//start a periodic check that every 60s parse all the github pages saved
periodicCheck($bot);


/**
 * Function that add a periodic timer to the main loop and every 10 seconds check the urls
 * saved in cache. If the stars number is different then it send a message to every users that want to be notified
 *
 * @param Zanzara $bot
 */
function periodicCheck(Zanzara $bot)
{
    $loop = $bot->getLoop();

    // definition of a periodic timer (checkout more on https://github.com/reactphp/event-loop#addperiodictimer)
    $loop->addPeriodicTimer(10, function ($timer) use ($loop, $bot) {

        $bot->getGlobalDataItem("reposToCheck")->then(function ($reposToCheck) use ($bot) {
            $edited = false;
            foreach ($reposToCheck as $key => $repo) {
                $newStars = getStars($repo["url"]);
                if ($newStars != $repo["stars"]) {
                    $reposToCheck[$key]["stars"] = $newStars;
                    $repoName = getRepoName($repo["url"]);
                    $edited = true;
                    foreach ($repo["watchers"] as $watcher) {
                        $bot->getTelegram()->sendMessage("stars on ${repoName}: " . $newStars, ["chat_id" => $watcher]);
                    }
                }
            }

            if ($edited) {
                $bot->setGlobalData("reposToCheck", $reposToCheck);
            }
        });
    });
}

/**
 * Given an url check on the page the number of stars
 * @param $url
 * @return bool|string
 */
function getStars($url)
{
    try {
        $client = new Client();
        $crawler = $client->request('GET', $url);
        // xpath query to get the span that contains the number of stars
        return $crawler->filterXPath("//*[@id=\"repos\"]/div[1]/nav/a[1]/span")->text();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Return name of repository from url
 * @param $url
 * @return mixed|string
 */
function getRepoName($url)
{
    // telegram doesn't like for example the character "-" so it won't display name of repo like zanzara-skeleton
    $splitUrl = explode("/", $url);
    $name = $splitUrl[count($splitUrl) - 2];
    return $name;
}


/**
 * Entry point of our but. This handler is fired when a /start message is set to the bot
 */
$bot->onCommand('start', function (Context $ctx) use ($bot) {
    // ask link to the user
    $ctx->sendMessage('Hi, send me the url of the stargazer repository page');
    // nextStep is used to set handler on conversation state. Now our application know that if a new message arrive the
    // handler firstParse must be used. In this way we can create conversational bot in an easy way.
    $ctx->nextStep("firstParse");
});


/**
 * Handler on conversation step
 * @param Context $ctx
 */
function firstParse(Context $ctx)
{
    // In this point I know that I asked a link, so the message sent by the user should be a link!
    $newUrl = $ctx->getMessage()->getText();
    // try to get the stars on the link
    $newStars = getStars($newUrl);
    //get the chatId of the user that sent the url (I will use this chatId to send a message if stars change
    $chatId = $ctx->getEffectiveUser()->getId();

    if ($newStars) {
        // send to the user the starting stars number

        $repoName = getRepoName($newUrl);

        $ctx->sendMessage("Starting stars on ${repoName}: " . $newStars);


        /**
         * Example of the data structure saved in cache on reposToCheck variable name
         *
         *  [
         *    [
         *      "url" => "https://github.com/badfarm/zanzara/stargazers",
         *      "watchers" => [11855858585, 10293838838]
         *    ],
         *    [
         *      "url" => "https://github.com/badfarm/zanzara-skeleton/stargazers",
         *      "watchers" => [11855858585]
         *    ]
         *  ]
         *
         */


        //know I check the cache. If it's the first /start of all users there is only an empty array
        $ctx->getGlobalDataItem("reposToCheck")->then(function ($reposToCheck) use ($newUrl, $newStars, $chatId, $ctx) {

            $founded = false;

            // iterate over the repository array items
            foreach ($reposToCheck as $key => $repo) {

                //if the url of the item saved in the cache is equal to the newUrl sent by the user
                if ($repo["url"] == $newUrl) {
                    // update the stars number
                    $reposToCheck[$key]["stars"] = $newStars;
                    // add the userId to the watchers if it is not already present
                    if (!in_array($chatId, $repo["watchers"])) {
                        array_push($reposToCheck[$key]["watchers"], $chatId);
                    }
                    $founded = true;

                    // stop the loop there is the match so I don't need to check over
                    break;
                }
            }

            // if I don't find the url saved in cache I need to add it.
            if (!$founded) {
                $newItem = [
                    "url" => $newUrl,
                    "stars" => $newStars,
                    "watchers" => [$chatId] //the only watcher in this case is the user that sent the url
                ];
                array_push($reposToCheck, $newItem);
            }

            // write into the cache the new reposToCheck array
            $ctx->setGlobalData("reposToCheck", $reposToCheck)->then(function ($result) use ($ctx) {
                if ($result) {
                    $ctx->sendMessage("Correctly set in watching list");
                } else {
                    $ctx->sendMessage("Something went wrong");
                }
            });
        });

        // clean the state the conversation in over
        $ctx->endConversation();

    } else {

        //I can't find any stars on the page so I tell the user to retry.
        $ctx->sendMessage("can't find stars on page, retry with the stargazer page");
    }
}

$bot->run();