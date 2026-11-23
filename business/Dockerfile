FROM node:24-bookworm-slim
WORKDIR /app
COPY package.json ./
COPY runtime/dist/server ./dist/server
COPY runtime/scripts ./scripts
COPY drizzle ./drizzle
RUN mkdir /data && chown node:node /data
USER node
EXPOSE 8080
CMD ["node","scripts/start.mjs"]
