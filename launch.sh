#!/bin/bash
set -e

echo "
    #########################    
  #############################  
#################################
#################################
###########----##################
###########----##################
###########----##################
###########----##################
###########----##################
###########----##################
###########----+#################
###########*--------+############
#############---------###########
###############*---=#############
#################################
%################################
  #############################  
    #########################    


------+##=----------   launchroom
------=##*-=--------   Framework-first, zero fuss self-hosted deployments
------=##*-=--------   https://github.com/vantezzen/launchroom

"
echo "🚀 Starting launchroom..."

PUBLIC_IP=$(curl -s ifconfig.me)
ENV_FILE="./resources/docker/.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "$ENV_FILE does not exist. Creating it..."

  # Generate random password (32 characters, alphanumeric)
  DB_PASSWORD=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 32)

  # Generate Laravel APP_KEY (base64 encoded 32 bytes, prefixed with 'base64:')
  APP_KEY="base64:$(openssl rand -base64 32)"

  # Generate Reverb credentials
  REVERB_APP_ID=$(openssl rand -hex 16)       # 32 characters hex
  REVERB_APP_KEY=$(openssl rand -hex 32)      # 64 characters hex
  REVERB_APP_SECRET=$(openssl rand -hex 32)   # 64 characters hex

  # Create the .env file with the values
  cat <<EOL > "$ENV_FILE"
DB_DATABASE=launchroom
DB_USERNAME=app
DB_PASSWORD=$DB_PASSWORD
APP_KEY=$APP_KEY
APP_URL=$PUBLIC_IP:8080

REVERB_APP_ID=$REVERB_APP_ID
REVERB_APP_KEY=$REVERB_APP_KEY
REVERB_APP_SECRET=$REVERB_APP_SECRET
REVERB_HOST=$PUBLIC_IP
EOL

  echo "$ENV_FILE created successfully."
else
  echo "$ENV_FILE already exists. Skipping creation."
fi

echo "Starting Docker containers..."
docker network create launchroom_net || echo "Network launchroom_net already exists."
docker compose -f resources/docker/docker-compose.yml -f resources/docker/docker-compose.launch.yml --env-file $ENV_FILE up -d --remove-orphans --build

echo "🚀 launchroom is now ready!"
echo "You can access it at:"
echo "Local: http://localhost:8080"
echo "Public: http://$PUBLIC_IP:8080"
