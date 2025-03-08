<img src="resources/js/assets/launchroom.svg" 
      alt="launchroom logo" 
      width="200"
      align="center"
/>

# launchroom

> Framework-first, zero fuss self-hosted deployments

launchroom brings Vercel's `Framework-defined infrastructure` to self-hosting.

As opposed to other self-hosting dashboards like Coolify, launchroom is not just a dashboard around Docker Compose\* but instead tries to abstract away the details of deploying a web application, while still giving you the flexibility to customize your deployment. Think Vercel or Laravel Cloud but on your own server - focused on specific frameworks to provide a seamless experience.

(\*: well, in the end it is a dashboard around Docker Compose, but you don't really have to interact with it)

## Installation

TBD

## Development

Install Docker, Docker Compose, PHP, Composer, Node.js and NPM.

1. Clone the repository
2. Run these commands:

```bash
# Install dependencies
composer install
npm install

# Setup Laravel
cp .env.example .env
php artisan key:generate
php artisan migrate --seed

# Setup Docker
docker network create launchroom_net
cd resources/docker && docker-compose up -d
```

3. Run `composer run dev` to start the PHP server

Open `http://localhost:8000` in your browser to see the application. You may login with the following credentials:

- Email: `test@example.com`
- Password: `password`
