import { chromium } from "playwright";

const url = process.argv[2];

if (!url) {
  console.error("Missing Shopee URL");
  process.exit(1);
}

function textOrEmpty(value) {
  return typeof value === "string" ? value.trim() : "";
}

function normalizeNumber(value) {
  if (typeof value === "number" && Number.isFinite(value)) return value;
  const raw = String(value ?? "")
    .replace(/[^\d,.-]+/g, "")
    .replace(/\.(?=\d{3}(\D|$))/g, "")
    .replace(",", ".")
    .trim();
  const parsed = Number(raw);
  return Number.isFinite(parsed) ? parsed : 0;
}

const browser = await chromium.launch({
  headless: true,
  args: ["--disable-blink-features=AutomationControlled"],
});

try {
  const page = await browser.newPage({
    viewport: { width: 1440, height: 1200 },
    userAgent:
      "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36",
    locale: "pt-BR",
  });

  await page.goto(url, {
    waitUntil: "domcontentloaded",
    timeout: 30000,
  });

  await page.waitForTimeout(2500);

  const data = await page.evaluate(() => {
    const html = document.documentElement.innerHTML;
    const title =
      document.querySelector('meta[property="og:title"]')?.getAttribute("content") ||
      document.querySelector('meta[name="twitter:title"]')?.getAttribute("content") ||
      document.querySelector("title")?.textContent ||
      "";

    const description =
      document.querySelector('meta[property="og:description"]')?.getAttribute("content") ||
      document.querySelector('meta[name="description"]')?.getAttribute("content") ||
      "";

    const imageNodes = Array.from(
      document.querySelectorAll('img[src*="shopee"], img[srcset*="shopee"], img')
    );

    const images = Array.from(
      new Set(
        imageNodes
          .map((img) => img.getAttribute("src") || img.getAttribute("data-src") || "")
          .map((src) => src.trim())
          .filter((src) => src.startsWith("http"))
      )
    ).slice(0, 12);

    const bodyText = document.body?.innerText || "";

    const responseTimeMatch =
      bodyText.match(/responde\s+em\s+([^\n]+)/i) ||
      bodyText.match(/tempo\s+de\s+resposta[^\n:]*:\s*([^\n]+)/i);

    const soldMatch = bodyText.match(/(\d+[.,]?\d*)\s*(vendidos|vendas|sold)/i);
    const ratingMatch = bodyText.match(/(\d+[.,]\d)\s+de\s+5/i) || bodyText.match(/(\d+[.,]\d)/i);
    const priceMeta =
      document.querySelector('meta[property="product:price:amount"]')?.getAttribute("content") ||
      "";

    const videoPresent =
      document.querySelector("video") !== null ||
      /video_url|mp4|"video"/i.test(html);

    const freeShipping = /frete\s+gr[aá]tis|envio\s+gr[aá]tis|free\s+shipping/i.test(bodyText);

    return {
      title,
      description,
      images,
      photo_count: images.length,
      has_video: videoPresent,
      free_shipping: freeShipping,
      sold_count: soldMatch?.[1] || "",
      rating: ratingMatch?.[1] || "",
      response_time_text: responseTimeMatch?.[1]?.trim() || "",
      price: priceMeta,
      tags: title.split(/\s+/).filter(Boolean).slice(0, 20),
    };
  });

  const result = {
    title: textOrEmpty(data.title),
    description: textOrEmpty(data.description),
    images: Array.isArray(data.images) ? data.images : [],
    photo_count: normalizeNumber(data.photo_count),
    has_video: Boolean(data.has_video),
    free_shipping: Boolean(data.free_shipping),
    sold_count: normalizeNumber(data.sold_count),
    rating: normalizeNumber(data.rating),
    response_time_text: textOrEmpty(data.response_time_text),
    price: normalizeNumber(data.price),
    tags: Array.isArray(data.tags) ? data.tags.map((item) => textOrEmpty(item)).filter(Boolean) : [],
  };

  process.stdout.write(JSON.stringify(result));
} finally {
  await browser.close();
}
