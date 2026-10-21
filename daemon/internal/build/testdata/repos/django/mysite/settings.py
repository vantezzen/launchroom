import os
SECRET_KEY = os.environ.get("SECRET_KEY", "dev")
WSGI_APPLICATION = "mysite.wsgi.application"
STATIC_URL = "static/"
STATIC_ROOT = "staticfiles"
