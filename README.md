# dril-archive

** DEAD. FUCK ELON MUSK.**

Due to [the uncertain future](https://twitter.com/mistydemeo/status/1590900599302029313) of [Twitter dot com](https://www.washingtonpost.com/technology/2022/10/27/twitter-elon-musk/) 
many have decided to pack their bags [to be ready to move on](https://mastodon.social/@mastodonusercount@bitcoinhackers.org/109365877178488409) in case the site [ceases to exist](https://twitter.com/alexeheath/status/1593399683086327808).
People started to worry for the whereabouts of [dril's tweets](https://twitter.com/dril) - a [modern](https://twitter.com/dril/status/900592164589248513) [prophet](https://twitter.com/dril/status/134167378639597568) - ["who could only emerge on an app like Twitter"](https://www.washingtonpost.com/technology/2022/11/22/dril-musk-twitter-future/).

I started to write [a backup tool](https://github.com/codemasher/twitter-archive) to augment the incomplete [twitter archive downloads](https://twitter.com/settings/download_your_data) and of course dril's feed was used for [testing purposes](https://twitter.com/codemasher/status/1594217145428152320).
Around the same time, [Nick Farruggia](https://twitter.com/nickfarruggia/status/1594121736987250688) shared a [Google spreadsheet with every dril tweet](https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w), that i then used as basis [to compile a JSON](https://gist.github.com/codemasher/d921cab21c3e684e6bb69219da900b4e) from the Twitter web API.
Eventually, the archive, along with the scripts i used to download and compile ended up in this repository in order to compile the data into other formats and run [a static website](https://codemasher.github.io/dril-archive/) via [GitHub pages](https://github.com/codemasher/dril-archive/tree/gh-pages).

## Downloads
Ok, this is a bit messy, but releases are hardly feasible for this type of repo. Instead, each build creates an artifact with the files that are committed to the gh-pages branch for the static website.
You can download these artifacts under ["actions/Build"](https://github.com/codemasher/dril-archive/actions/workflows/build.yml) and [filter by "scheduled"](https://github.com/codemasher/dril-archive/actions/workflows/build.yml?query=event%3Aschedule).
Click on the latest workflow and scroll down to "artifacts" - there you are!

## Requirements
- PHP 8.1+
  - cURL extension enabled
  - [composer](https://getcomposer.org/download/) for package installation
  - a [Twitter developer account](https://developer.twitter.com/en/portal/projects-and-apps)

## Installation
### PHP for Windows
- download the latest PHP for your system (usually x64, thread safe) from [windows.php.net](https://windows.php.net/download/) and unzip it to a folder of your choice
- copy/rename the `php.ini-development` to `php.ini` and open the latter in an editor
  - search for `extension_dir`, uncomment this line (under "on windows")
  - search for `extension=curl`, uncomment this line (remove the semicolon)
  - search for `extension=mbstring`, uncomment this line
  - search for `extension=openssl`, uncomment this line
- install composer: [Windows install](https://getcomposer.org/Composer-Setup.exe)
- optional: add PHP and Composer to your system `PATH`
- optional: install [git for windows](https://git-scm.com/download/win) and/or the [GitHub desktop client](https://desktop.github.com/)

It might be necessary to provide a CA file for OpenSSL:
- download the cacert.pem from https://curl.se/ca/cacert.pem (e.g. into the PHP folder)
- in the php.ini
  - search for `curl.cainfo`, uncomment this line and add `.\cacert.pem` or `c:\path\to\cacert.pem`
  - search for `openssl.cafile`, uncomment this line and add the same path to the cacert.pem as above

### PHP for Linux
- add the PPA [ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php): `sudo apt-add-repository ppa:ondrej/php`
- update the package list: `sudo apt-get update`
- install php: `sudo apt-get install php8.1-cli php8.1-common php8.1-curl php8.1-mbstring php8.1-openssl php8.1-xml`
- install composer: [installation guide](https://www.digitalocean.com/community/tutorials/how-to-install-and-use-composer-on-ubuntu-20-04)

A PHP installation guide can be found [over here on digitalocean.com](https://www.digitalocean.com/community/tutorials/how-to-install-php-8-1-and-set-up-a-local-development-environment-on-ubuntu-22-04).

### Library installation
- download [the zip file](https://github.com/codemasher/dril-archive/archive/refs/heads/main.zip) or clone the repo via `git clone https://github.com/codemasher/dril-archive` (or use the desktop client)
- in the library root, next to the `composer.json` run `composer install` to install the dependencies
- in the `/config` folder, copy the `.env_example` to `.env`, open it in an editor and fill in the details for your twitter developer account - currently, only the Bearer token is necessary.
- download [the dril spreadsheet](https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w) and save it as `/.build/dril.csv`

## Usage
### Initial build
The official timeline/search API endpoints only return up to 3200 tweets unless you have [academic access](https://developer.twitter.com/en/docs/twitter-api/getting-started/about-twitter-api#v2-access-level). So the initial build uses the undocumented/inoffical adaptive search API that's being used by [Twitter's web search](https://twitter.com/explore), which means, the tokens from the developer account won't work here.
Obtaining the credentials is a bit messy and described in the following steps:

- open `/cli/build-clean.php` in an editor
- open https://twitter.com/search in a webbrowser (chrome or firefox recommended), ideally in an incognito tab
- open the developer console (press F12)
- type anything in the twitter search box, hit enter
- go to the "network" tab in the dev console and filter the requests for `adaptive.json`
- click that line, a new tab for that request appears
- there, in the "headers" tab, scroll to "request headers" and look for `Authorization: Bearer ...`
- right click that line, select "copy value" and replace the value of `$requestToken` in `build-clean.php`
- scroll a bit further and look for the `x-guest-token` header, copy the value and replace the `$guestToken` value in `build-clean.php`
- adjust the `$query` variable to your liking (a valid [twitter search](https://developer.twitter.com/en/docs/twitter-api/tweets/search/integrate/build-a-query)) - omit the `since` and `until` keywords here to download the user's full timeline 

The `x-guest-token` is valid for about 2 hours, the bearer token at least for a day.

Now that everything's set up, you can run `php build-clean.php` in the `./cli` directory and watch the console for a while... :tea:
The output will be stored under `/output` and you can open the [`index.html`](./output/index.html) in a browser, the API responses are cached under `/.build/<query-value>`.

### Incremental update
The incremental update utilizes [the v1 API search endpoint](https://developer.twitter.com/en/docs/twitter-api/v1/tweets/search/api-reference/get-search-tweets), which returns 20 results with standard access.
It will also update the user profiles in the timeline. The query should be the same as the one used to run the initial build.
Run `php incremental-update.php` in `./cli` and grab :coffee: (this script is also used in [the daily-run workflow](https://github.com/codemasher/dril-archive/blob/main/.github/workflows/build.yml)).

### Counter update
The counter update uses [the v2 tweets endpoint](https://developer.twitter.com/en/docs/twitter-api/tweets/lookup/api-reference/get-tweets) with the `tweet.fields=public_metrics` expansion to update the stale counter values of an existing timeline.
Run `php update-counts.php` in `./cli` :cake:

## Disclaimer
The scripts to create the archive are licensed under the [WTFPL](http://www.wtfpl.net/).<br>
All tweets and media remain under copyright by their respective creators.
