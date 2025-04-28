#!/bin/bash
set -e

echo "🚀 Starting Launchroom..."

ENV_FILE="./resources/docker/.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "$ENV_FILE does not exist. Creating it..."

  # Generate random password (32 characters, alphanumeric)
  DB_PASSWORD=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 32)

  # Generate Laravel APP_KEY (base64 encoded 32 bytes, prefixed with 'base64:')
  APP_KEY="base64:$(openssl rand -base64 32)"

  # Create the .env file with the values
  cat <<EOL > "$ENV_FILE"
DB_DATABASE=launchroom
DB_USERNAME=app
DB_PASSWORD=$DB_PASSWORD
APP_KEY=$APP_KEY
APP_URL=localhost:8080
EOL

  echo "$ENV_FILE created successfully."
else
  echo "$ENV_FILE already exists. Skipping creation."
fi

echo "🚀 Starting Docker containers..."
docker network create launchroom_net || echo "Network launchroom_net already exists."
docker compose -f resources/docker/docker-compose.yml -f resources/docker/docker-compose.launch.yml --env-file $ENV_FILE up -d --remove-orphans --build

echo "🚀 Launchroom is now ready!"
echo "🚀 You can access it at http://localhost:8080"