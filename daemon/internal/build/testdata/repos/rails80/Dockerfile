# syntax=docker/dockerfile:1
ARG RUBY_VERSION=3.3.6
FROM docker.io/library/ruby:$RUBY_VERSION-slim AS base
WORKDIR /rails
ENV RAILS_ENV="production"
FROM base AS build
RUN bundle install
FROM base
COPY --from=build /rails /rails
USER 1000:1000
ENTRYPOINT ["/rails/bin/docker-entrypoint"]
EXPOSE 80
CMD ["./bin/thrust", "./bin/rails", "server"]
