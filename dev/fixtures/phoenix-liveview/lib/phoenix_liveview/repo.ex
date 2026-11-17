defmodule PhoenixLiveview.Repo do
  use Ecto.Repo,
    otp_app: :phoenix_liveview,
    adapter: Ecto.Adapters.Postgres
end
