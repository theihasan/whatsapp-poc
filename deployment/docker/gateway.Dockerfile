# Internal WhatsApp gateway. Never publish its port; Laravel reaches it on the
# private network only.
FROM node:22-bookworm-slim AS build
WORKDIR /gateway
COPY services/whatsapp-gateway/package.json services/whatsapp-gateway/package-lock.json ./
RUN npm ci
COPY services/whatsapp-gateway/ ./
RUN npm run build

FROM node:22-bookworm-slim
ENV NODE_ENV=production
WORKDIR /gateway
COPY services/whatsapp-gateway/package.json services/whatsapp-gateway/package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY --from=build /gateway/dist ./dist
RUN mkdir -p /data && chown node:node /data
USER node
ENV GATEWAY_DATA_DIR=/data GATEWAY_HOST=0.0.0.0
VOLUME ["/data"]
HEALTHCHECK --interval=30s --timeout=5s CMD node -e "fetch('http://127.0.0.1:'+(process.env.GATEWAY_PORT||3100)+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
CMD ["node", "--disable-warning=ExperimentalWarning", "dist/index.js"]
