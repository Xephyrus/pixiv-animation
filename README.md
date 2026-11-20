# Pixiv Animation

用 PHP + SQLite 实现的 Pixiv 作品抓取与播放站：输入作品 ID 抓取插画 / 漫画 / 动图，元数据落库，动图按真实帧延迟在网页里播放。

![test-and-build](https://github.com/Xephyrus/pixiv-animation/actions/workflows/test-and-build.yml/badge.svg)

## 功能

- **作品库**：分页展示、关键词搜索（角色 / 标题 / ID / 标签）、主题切换
- **抓取下载**：按作品 ID 或链接抓取，`illustType` 0/1 取原图，2 下载 ugoira 压缩包并解压
- **实时进度**：下载接口以 SSE 推送开始 / 单文件进度 / 完成 / 出错事件，页面进度条与日志即时更新
- **并发下载**：多图作品按 `DOWNLOAD_CONCURRENCY` 并行下载，失败文件自动换扩展名重试
- **动图播放**：读取 `meta_json` 里的 `frames.file` 与逐帧 `delay`，按原速播放，支持播放 / 暂停与进度显示
- **元数据**：动图记录 `src`、`originalSrc`、`mime_type` 与逐帧延迟，播放节奏与 Pixiv 一致
- **本地同步**：扫描已有的 `downloads/{id}_ugoira1920x1080/` 目录入库
- **编辑信息**：`/edit/{id}` 可修改人物名称、标题、显示名与标签；下载页的人物名称不再缓存填充
- **元数据修复**：可用登录 Cookie 批量重抓，或直接导入浏览器里复制的 `ugoira_meta` 响应
- **容器与 CI**：提供 Dockerfile，GitHub Actions 跑语法检查、冒烟测试与镜像构建

## 环境要求

- PHP **8.1+**（开发机为 8.5），扩展：`pdo_sqlite`、`sqlite3`、`curl`、`zip`、`openssl`
- 能访问 `www.pixiv.net` / `i.pximg.net` 的网络环境；本机代理默认探测 `127.0.0.1:7897`、`127.0.0.1:7890`
- 已登录 Pixiv 的 Cookie（动图与受限作品的 `ugoira_meta`、原图下载都需要）。必须是完整的 `Cookie` 请求头，包含 HttpOnly 的 `PHPSESSID`；浏览器 `document.cookie` 读不到它，只贴 `document.cookie` 会以未登录身份请求，受限作品会返回 404

## 快速开始

```sh
git clone https://github.com/Xephyrus/pixiv-animation.git
cd pixiv-animation

cp config.env.example config.env   # 按需修改端口 / 代理 / 路径
php scripts/sync.php               # 可选：把已有 ugoira 目录同步进数据库
./scripts/start.sh                 # Windows: ./scripts/start.ps1
```

打开：

- 图库 <http://127.0.0.1:5400/>
- 下载页 <http://127.0.0.1:5400/download>
- 动图播放 <http://127.0.0.1:5400/play/{id}>
- 编辑信息 <http://127.0.0.1:5400/edit/{id}>

也可以不用启动脚本：

```sh
php -S 0.0.0.0:5400 router.php
```

## 配置

复制 `config.env.example` 为 `config.env`（该文件已被 `.gitignore` 忽略）：

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `HOST` | `0.0.0.0` | 监听地址 |
| `PORT` | `5400` | 端口 |
| `DOWNLOAD_DIR` | `./downloads` | 插画 / 漫画目录 |
| `UGOIRA_DIR` | `./downloads` | 动图目录（`{id}_ugoira1920x1080`） |
| `DB_PATH` | `./data/pixiv.db` | SQLite 文件 |
| `PROXY_HOST` | `127.0.0.1` | 下载代理地址 |
| `PROXY_PORT` | `7897` | 下载代理端口 |
| `DOWNLOAD_DELAY_MS` | `1000` | 仅当并发数为 1 时，多图作品的下载间隔 |
| `DOWNLOAD_CONCURRENCY` | `4` | 同时下载的文件数（1–8），越大越快，但请求更密集 |
| `DOWNLOAD_TIMEOUT_SEC` | `60` | 单次请求超时 |
| `CURL_CA_FILE` | `./data/cacert.pem` | CA 证书 |
| `SSL_VERIFY` | `1` | 代理做 HTTPS 解密报证书错误时可设 `0` |

Cookie 只随单次请求发送，不写入数据库或配置文件；网页端填写的 Cookie 保存在浏览器本地。

## Docker

```sh
docker build -t pixiv-animation .
docker run -d --name pixiv-animation \
  -p 5400:5400 \
  -v "$PWD/data:/var/www/html/data" \
  -v "$PWD/downloads:/var/www/html/downloads" \
  pixiv-animation
```

容器内访问宿主代理需要额外配置，例如：

```sh
docker run -d --name pixiv-animation -p 5400:5400 \
  --add-host=host.docker.internal:host-gateway \
  -e PROXY_HOST=host.docker.internal -e PROXY_PORT=7897 \
  pixiv-animation
```

## API

接口前缀 `/api`，返回 `{"success":true,"data":...}` 或 `{"success":false,"error":...}`。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/health` | 健康检查 |
| GET | `/api/list` | 作品列表，支持 `page`、`pageSize`、`search`、`illustType`、`sort`、`order` |
| GET | `/api/works/{id}` | 单条作品（含 `files`、`coverUrl`、`viewUrl`） |
| GET | `/api/illust/{id}` | 代理 Pixiv `ajax/illust` |
| GET | `/api/ugoira_meta/{id}` | 代理 Pixiv `ugoira_meta` |
| POST | `/api/download` | 按 ID 抓取并落库，可带 `redownload`、`character` |
| PUT | `/api/works/{id}` | 更新作品信息（`character`、`title`、`name`、`tags`） |
| PUT | `/api/works/{id}/character` | 更新动图人物名 |
| DELETE | `/api/works/{id}` | 删除作品，可带 `deleteFiles` |
| POST | `/api/sync` | 扫描已有 ugoira 目录入库 |

Cookie 与代理端口可通过请求头 `X-Pixiv-Cookie`、`X-Proxy-Port` 传入；也可以直接带浏览器里的标准 `Cookie` 请求头，例如 `curl -H "Cookie: PHPSESSID=..." http://127.0.0.1:5400/api/ugoira_meta/{id}`。

`POST /api/download` 支持两种返回方式：请求头带 `Accept: text/event-stream` 时以 SSE 推送 `open` / `start` / `file` / `progress` / `info` / `done` / `error` 事件，下载页即用这种方式实时显示进度；不带时仍返回下载完成后的最终 JSON。注意 `php -S` 是单进程，下载期间其它请求会排队，生产环境建议用 nginx / php-fpm 或容器部署。

## 命令行脚本

```sh
# 扫描 downloads/{id}_ugoira1920x1080 目录入库
php scripts/sync.php

# 用 Cookie 重新抓取 ugoira_meta，只重写 works.meta_json（写前自动备份数据库）
php scripts/refresh_meta.php --cookie-file=./cookie.txt --dry-run
php scripts/refresh_meta.php --cookie-file=./cookie.txt --only=151545941

# 从浏览器复制的 ugoira_meta 响应写入 meta_json（同样只改这一列）
php scripts/import_meta_json.php meta.json --dry-run
```

`refresh_meta.php` 需要能拿到 `ugoira_meta` 的登录 Cookie；R-18 与“仅登录可见”的作品匿名请求会返回 404。

## 数据结构

SQLite 两张表：

- `works`：作品主记录（标题、人物、类型、路径、大小、标签、`meta_json` 等）
- `files`：作品下的文件名与大小

`meta_json` 只对动图有意义，结构如下：

```json
{
  "src": "https://i.pximg.net/img-zip-ugoira/img/.../151545941_ugoira600x600.zip",
  "originalSrc": "https://i.pximg.net/img-zip-ugoira/img/.../151545941_ugoira1920x1080.zip",
  "mime_type": "image/jpeg",
  "frames": [
    { "file": "000000.jpg", "delay": 80 },
    { "file": "000001.jpg", "delay": 50 }
  ]
}
```

## 目录结构

```
api/                  API 路由
assets/               前端 CSS / JS / 图标
data/                 SQLite 数据库与 CA 证书
downloads/            作品文件（{id}/ 或 {id}_ugoira1920x1080/）
scripts/              CLI 脚本与启动脚本
src/                  核心类（Config / Database / HttpClient / PixivApi / Downloader）
web/                  页面模板
router.php            内置服务器路由
```

## 持续集成

`.github/workflows/test-and-build.yml` 在 push 到 `master`、Pull Request 与手动触发时执行：

1. PHP 8.2 / 8.3 / 8.4 矩阵下做 `php -l` 全量语法检查，并启动内置服务器做冒烟测试（`/api/health`、`/api/list`、首页、下载页、`sync.php`）
2. 构建 Docker 镜像并以容器方式启动，验证 `/api/health`

## 说明

- 仅用于个人备份与浏览已购买 / 可访问的作品，请遵守 Pixiv 的服务条款与当地法律，不要二次分发。
- 项目不含任何账号凭证；`config.env`、数据库、下载内容都已加入 `.gitignore`。
