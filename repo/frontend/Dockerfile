FROM node:18-alpine AS build

# API_BASE_URL is baked into the bundle at build time via webpack's
# DefinePlugin. Override with `--build-arg API_BASE_URL=...` when the
# frontend must point at a non-default backend (e.g., staging domains or
# absolute URLs). Defaults to the relative `/api/v1` path so nginx can
# reverse-proxy in the standard production container.
ARG API_BASE_URL=/api/v1
ENV API_BASE_URL=${API_BASE_URL}

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install

COPY . .
RUN npm run build

FROM nginx:alpine

COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
