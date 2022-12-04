# dril-archive

Due to [the uncertain future](https://twitter.com/mistydemeo/status/1590900599302029313) of [Twitter dot com](https://www.washingtonpost.com/technology/2022/10/27/twitter-elon-musk/) 
many have decided to pack their bags [to be ready to move on](https://mastodon.social/@mastodonusercount@bitcoinhackers.org/109365877178488409) in case the site [ceases to exist](https://twitter.com/alexeheath/status/1593399683086327808).
People started to worry for the whereabouts of [dril's tweets](https://twitter.com/dril) - a [modern](https://twitter.com/dril/status/900592164589248513) [prophet](https://twitter.com/dril/status/134167378639597568) - ["who could only emerge on an app like Twitter"](https://www.washingtonpost.com/technology/2022/11/22/dril-musk-twitter-future/).

I started to write [a backup tool](https://github.com/codemasher/twitter-archive) to augment the incomplete [twitter archive downloads](https://twitter.com/settings/download_your_data) and of course dril's feed was used for [testing purposes](https://twitter.com/codemasher/status/1594217145428152320).
Around the same time, [Nick Farruggia](https://twitter.com/nickfarruggia/status/1594121736987250688) shared a [Google spreadsheet with every dril tweet](https://docs.google.com/spreadsheets/d/1juZ8Dzx-hVCDx_JLVOKI1eHzBlURHd7u6dqkb3F8q4w), that i then used as basis [to compile a JSON](https://gist.github.com/codemasher/d921cab21c3e684e6bb69219da900b4e) from the Twitter web API.
Eventually, the archive, along with the scripts i used to download and compile ended up in this repository in order to compile the data into other formats and run a static website via GH pages.

## Downloads
Ok, this is a bit messy, but releases are hardly feasible for this type of repo. Instead, each build creates an artifact with the files that are committed to [the gh-pages branch](https://github.com/codemasher/dril-archive/tree/gh-pages) for [the static website](https://codemasher.github.io/dril-archive/).
You can download these artifacts under ["actions/Build"](https://github.com/codemasher/dril-archive/actions/workflows/build.yml) and [filter by "scheduled"](https://github.com/codemasher/dril-archive/actions/workflows/build.yml?query=event%3Aschedule).
Click on the latest workflow and scroll down to "artifacts" - there you are!

## Disclaimer
The scripts to create the archive are licensed under the [WTFPL](http://www.wtfpl.net/).<br>
All tweets and media remain under copyright by their respective creators.
