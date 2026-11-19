const bootstrapEl = document.getElementById("galleryBootstrap");
const main = document.getElementById("main");
const workCount = document.getElementById("workCount");
const pagination = document.getElementById("pagination");
const paginationSummary = document.getElementById("paginationSummary");
const paginationControls = document.getElementById("paginationControls");
const searchForm = document.getElementById("gallerySearchForm");
const searchInput = document.getElementById("gallerySearch");

const initial = bootstrapEl ? JSON.parse(bootstrapEl.textContent || "{}") : {};
const pageSize = Number(initial.pageSize) || 20;
let currentPage = Number(initial.page) || 1;
let totalWorks = Number(initial.total) || 0;
let search = String(initial.search || "");
let works = Array.isArray(initial.items) ? initial.items : [];
let requestId = 0;

function typeLabel(type) {
  if (type === 2) return "UGOIRA";
  if (type === 1) return "MANGA";
  return "ILLUST";
}

function typeIcon(type) {
  if (type === 2) return "fa-film";
  if (type === 1) return "fa-book-open";
  return "fa-image";
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function createPageButton(page, { label, icon, current = false, edge = false, disabled = false }) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = `pagination-button${edge ? " pagination-edge" : ""}`;
  button.dataset.page = String(page);
  button.setAttribute("aria-label", label);
  button.disabled = disabled;
  if (current) button.setAttribute("aria-current", "page");

  if (icon) {
    const iconElement = document.createElement("i");
    iconElement.className = `fa-solid ${icon}`;
    iconElement.setAttribute("aria-hidden", "true");
    button.append(iconElement);
  }

  if (edge) {
    const text = document.createElement("span");
    text.className = "pagination-button-text";
    text.textContent = label;
    button.append(text);
  } else {
    button.append(document.createTextNode(String(page)));
  }

  return button;
}

function getVisiblePages(totalPages) {
  if (totalPages <= 7) {
    return Array.from({ length: totalPages }, (_, index) => index + 1);
  }

  let start = Math.max(2, currentPage - 2);
  let end = Math.min(totalPages - 1, currentPage + 2);
  if (currentPage <= 4) end = 5;
  if (currentPage >= totalPages - 3) start = totalPages - 4;

  const pages = [1];
  if (start > 2) pages.push("ellipsis");
  for (let page = start; page <= end; page++) pages.push(page);
  if (end < totalPages - 1) pages.push("ellipsis");
  pages.push(totalPages);
  return pages;
}

function syncUrl() {
  const url = new URL(window.location.href);
  if (currentPage > 1) url.searchParams.set("page", String(currentPage));
  else url.searchParams.delete("page");
  if (search) url.searchParams.set("search", search);
  else url.searchParams.delete("search");
  const next = `${url.pathname}${url.search}${url.hash}`;
  const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (next !== current) {
    history.replaceState({ page: currentPage, search }, "", next);
  }
}

function renderPagination(totalPages, start, end) {
  pagination.hidden = totalPages <= 1;
  if (pagination.hidden) {
    paginationSummary.textContent = "";
    paginationControls.replaceChildren();
    return;
  }

  paginationSummary.textContent = `显示第 ${start + 1}–${end} 部，共 ${totalWorks} 部`;

  const buttons = [
    createPageButton(1, {
      label: "首页",
      icon: "fa-angles-left",
      edge: true,
      disabled: currentPage === 1,
    }),
    createPageButton(Math.max(1, currentPage - 1), {
      label: "上一页",
      icon: "fa-angle-left",
      edge: true,
      disabled: currentPage === 1,
    }),
  ];

  for (const page of getVisiblePages(totalPages)) {
    if (page === "ellipsis") {
      const ellipsis = document.createElement("span");
      ellipsis.className = "pagination-ellipsis";
      ellipsis.setAttribute("aria-hidden", "true");
      ellipsis.textContent = "…";
      buttons.push(ellipsis);
      continue;
    }

    buttons.push(
      createPageButton(page, {
        label: `第 ${page} 页`,
        current: page === currentPage,
      }),
    );
  }

  buttons.push(
    createPageButton(Math.min(totalPages, currentPage + 1), {
      label: "下一页",
      icon: "fa-angle-right",
      edge: true,
      disabled: currentPage === totalPages,
    }),
    createPageButton(totalPages, {
      label: "尾页",
      icon: "fa-angles-right",
      edge: true,
      disabled: currentPage === totalPages,
    }),
  );
  paginationControls.replaceChildren(...buttons);
}

function hydrateCoverImages() {
  main.querySelectorAll(".work-card-media img").forEach((img, index) => {
    if (index < 8) {
      img.loading = "eager";
      img.fetchPriority = "high";
    }

    const media = img.closest(".work-card-media");
    const reveal = () => {
      img.classList.add("is-loaded");
      media?.classList.add("is-ready");
    };

    if (img.complete && img.naturalWidth > 0) {
      reveal();
      return;
    }

    img.addEventListener("load", reveal, { once: true });
    img.addEventListener("error", reveal, { once: true });
  });
}

function renderPage() {
  const totalPages = Math.max(1, Math.ceil(totalWorks / pageSize));
  workCount.textContent = `${totalWorks} 部作品`;

  if (totalWorks === 0) {
    main.innerHTML = search
      ? `<p class="gallery-description gallery-empty">没有匹配的作品，试试其他关键词。</p>`
      : `<p class="gallery-description gallery-empty">暂无作品。请先到 <a href="/download">下载页</a> 抓取，或执行同步。</p>`;
    renderPagination(1, 0, 0);
    return;
  }

  currentPage = Math.min(currentPage, totalPages);
  const start = (currentPage - 1) * pageSize;
  const end = Math.min(start + works.length, totalWorks);
  main.innerHTML = works
    .map((work, index) => {
      const title = escapeHtml(work.character || work.title || work.name || work.id);
      const cover = escapeHtml(work.coverUrl || "");
      const href = escapeHtml(work.viewUrl || "#");
      const badge = typeLabel(work.illustType);
      const icon = typeIcon(work.illustType);
      const action = work.illustType === 2 ? "查看动画" : "查看作品";
      const kind =
        work.illustType === 2 ? "动态插画" : work.illustType === 1 ? "漫画" : "插画";
      const eager = index < 8;
      return `
                <article class="work-card">
                    <a class="work-card-link" href="${href}">
                        <div class="work-card-media"${cover ? ` style="--work-cover: url('${cover}')"` : ""}>
                            <img src="${cover}"
                                alt="${title} 预览"
                                width="400" height="600"
                                loading="${eager ? "eager" : "lazy"}"
                                decoding="async"
                                ${eager ? 'fetchpriority="high"' : ""}>
                            <span class="work-card-badge"><i class="fa-solid ${icon}" aria-hidden="true"></i> ${badge}</span>
                            ${work.illustType === 2 ? '<span class="work-card-play" aria-hidden="true"><i class="fa-solid fa-play"></i></span>' : ""}
                        </div>
                        <div class="work-card-content">
                            <h2 class="work-card-title">${title}</h2>
                            <p class="work-card-meta"><i class="fa-solid fa-hashtag" aria-hidden="true"></i><span>Pixiv ${kind} · ${escapeHtml(work.id)}</span></p>
                            <span class="work-card-action">
                                <span class="work-card-action-label"><i class="fa-regular fa-circle-play" aria-hidden="true"></i> ${action}</span>
                                <i class="fa-solid fa-arrow-right work-card-arrow" aria-hidden="true"></i>
                            </span>
                        </div>
                    </a>
                </article>
            `;
    })
    .join("");
  hydrateCoverImages();
  renderPagination(totalPages, start, end);
}

async function loadPage(page = currentPage, nextSearch = search) {
  const id = ++requestId;
  currentPage = page;
  search = nextSearch;

  try {
    const params = new URLSearchParams({
      page: String(currentPage),
      pageSize: String(pageSize),
    });
    if (search) params.set("search", search);

    const res = await fetch(`/api/list?${params}`);
    const json = await res.json();
    if (id !== requestId) return;
    if (!res.ok || json.success === false) {
      throw new Error(json.error || "加载失败");
    }

    works = json.data.items || [];
    totalWorks = json.data.total || 0;
    currentPage = json.data.page || currentPage;
    renderPage();
    syncUrl();
  } catch (error) {
    if (id !== requestId) return;
    main.innerHTML = `<p class="gallery-description gallery-empty">无法加载作品列表：${escapeHtml(error.message || error)}</p>`;
    pagination.hidden = true;
  }
}

paginationControls?.addEventListener("click", (event) => {
  const button = event.target.closest("button[data-page]");
  if (!button || button.disabled) return;

  loadPage(Number(button.dataset.page)).then(() => {
    paginationControls.querySelector('[aria-current="page"]')?.focus();
    main.scrollIntoView({ behavior: "smooth", block: "start" });
  });
});

searchForm?.addEventListener("submit", (event) => {
  event.preventDefault();
  const nextSearch = searchInput?.value.trim() || "";
  loadPage(1, nextSearch);
});

let searchTimer = 0;
searchInput?.addEventListener("input", () => {
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => {
    const nextSearch = searchInput.value.trim();
    if (nextSearch === search) return;
    loadPage(1, nextSearch);
  }, 280);
});

hydrateCoverImages();
