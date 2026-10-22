# SimpleMappr

Create geographic maps for publications and presentations from your location data.
Plot points, shade regions, add drawings and labels, and choose how your map looks.
Download the result as an image, a Word document, or a PowerPoint presentation.

The original SimpleMappr online service has been retired. This version continues the project for self-hosted use, on your own computer or a server you manage. Online hosting with separate accounts and social logins are not yet supported.

## Requirements

To run SimpleMappr on your own computer, you need:

- Docker with Docker Compose v2 installed and running.
- Git and Python 3 available in your terminal.
- A web browser.
- An internet connection for the first download and build.

Docker includes the application, map renderer, and database.
The commands below use a Unix-style terminal. On Windows, use WSL.

## Install SimpleMappr

For a new installation, open a terminal and run:

```sh
git clone https://github.com/jky7/SimpleMappr.git
cd SimpleMappr
docker build -t simplemappr-runtime:local .
scripts/configure-local-owner
docker compose up --build -d --wait
```

The setup asks you to choose an owner username and password. Use a password of at least 12 characters.
Keep these credentials to sign in and save maps. The first build downloads the required software and can take several minutes.

Once startup finishes, SimpleMappr will be available at [http://127.0.0.1:8080](http://127.0.0.1:8080/).
For an existing installation, keep its `.env` file and follow the [maintenance guide](docs/private-deployment.md).

## Open SimpleMappr

Open [http://127.0.0.1:8080](http://127.0.0.1:8080/) in your browser.
You can make and download maps without signing in.

## Make your first map

1. Open **Point Data**.
2. In **Layer 1**, enter a name in **Legend** and paste your coordinates into the box below it.
   Use one location per line, with latitude first and longitude second. For example, these points mark Montréal and Toronto:

   ```text
   45.5019,-73.5674
   43.6532,-79.3832
   ```

3. Click **Preview** to see your points on the map. Adjust the marker color, map layers, and projection to suit your figure.
4. Click **Download**, then choose the format you need. PNG works for an image; SVG is useful for further editing.

You can also import a data file using **Import map data or settings** on the **Point Data** tab.
Open **Help** in the application for coordinate examples and explanations of the map controls.

## Save and share a map

To save a map for later, use **Sign In** with the owner login created during installation.
Save your map from **Preview**, then reopen it from **My Maps**. Saved maps are private.

In **My Maps**, click **Share** to create a link, or **Unshare** to turn that link off.
Anyone who can access your SimpleMappr installation and has the link can view the shared map.
Sharing a map on your own computer does not make your computer accessible over the internet.

## More guides

See [Install and manage SimpleMappr](docs/private-deployment.md) for setup, map datasets, backups, and updates.
Developers can use the [development guide](docs/development.md) and [feature verification guide](docs/feature-verification.md).
The [original installation instructions](docs/legacy-readme.md) are archived for historical reference.

## Credits and license

SimpleMappr was created by [David P. Shorthouse](https://github.com/dshorthouse/SimpleMappr). This project continues his work.

The software is released under the [MIT license](LICENSE).
See [Third-party software and map data](docs/third-party.md) for data sources and their terms.
