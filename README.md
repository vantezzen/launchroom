# launchroom

> Framework-first, zero fuss self-hosted deployments

launchroom brings Vercel's `Framework-defined infrastructure` to self-hosting.

As opposed to other self-hosting dashboards like Coolify, launchroom is not just a fancy dashboard around Docker Compose but instead tries to abstract away the details of deploying a web application, while still giving you the flexibility to customize your deployment. Think Vercel or Laravel Cloud but on your own server - focused on specific frameworks to provide a seamless experience.

## Installation

TBD

## Development

Install Docker, Docker Compose, PHP, Composer, Node.js and NPM.

1. Clone the repository
2. Run `composer install` and `npm install`
3. Copy `.env.example` to `.env` and fill in the required values
4. Create the shared network with `docker network create launchroom_net`
5. Run `cd resources/docker && docker-compose up -d` to start the proxy server
6. Run `php artisan migrate:fresh --seed` to set up the development database
7. Run `composer run dev` to start the PHP server

Open `http://localhost:8000` in your browser to see the application. You may login with the following credentials:

- Email: `test@example.com`
- Password: `password`
