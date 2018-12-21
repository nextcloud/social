# Nextcloud Social

**🎉 Nextcloud becomes part of the federated social networks!**

The app is in alpha stage, so it’s time for you to [get involved! 👩‍💻](https://github.com/nextcloud/social#development-setup)

Some requirements in this alpha stage are that your Nextcloud:
- must use a real SSL certificate
- must be accessible from the internet
- must run on the default port

![](img/screenshot.png)

- **🙋 Find your friends:** No matter if they use Nextcloud, [🐘 Mastodon](https://joinmastodon.org), [🇫 Friendica](https://friendi.ca), and soon [✱ Diaspora](https://joindiaspora.com), [👹 MediaGoblin](https://www.mediagoblin.org) and more – you can follow them!
- **📜 Profile info:** No need to fill out more profiles – your info from Nextcloud will be used and extended.
- **👐 Own your posts:** Everything you post stays on your Nextcloud!
- **🕸 Open standards:** We use the established [ActivityPub](https://en.wikipedia.org/wiki/ActivityPub) standard!
- **🎨 Nice illustrations:** Made by [Katerina Limpitsouni of unDraw](https://undraw.co).

## Reset app
If you want to reset all Social app data e.g. to change the domain used for Social, you can use `occ social:reset` (For how to use occ commands see documentation: [using the occ command](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html)).

## Development setup

1. ☁ Clone the app into the `apps` folder of your Nextcloud: `git clone https://github.com/nextcloud/social.git`
2. 👩‍💻 Run `make dev-setup` to install the dependencies
3. 🏗 To build the Javascript whenever you make changes, run `make build-js`
4. ✅ Enable the app through the app management of your Nextcloud
5. 🎉 Partytime! Help fix [some issues](https://github.com/nextcloud/social/issues) and [review pull requests](https://github.com/nextcloud/social/pulls) 👍


![](img/social-promo.png)
