import http from "k6/http";
import { check, fail, sleep } from "k6";
import exec from "k6/execution";
import { Counter } from "k6/metrics";

const BASE_URL = __ENV.BASE_URL || "http://127.0.0.1:8080";
const VUS = Number(__ENV.VUS || 100);
const DURATION = __ENV.DURATION || "1m";
const SLEEP_SECONDS = Number(__ENV.SLEEP_SECONDS || 1);
const CHECKOUT_QUANTITY = Number(__ENV.CHECKOUT_QUANTITY || 1);
const CHECKOUT_STOCK = Number(__ENV.CHECKOUT_STOCK || 10000);

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 299 }, 409, 429));

const finance1Responses = new Counter("finance_1_responses");
const finance2Responses = new Counter("finance_2_responses");

export const options = {
  scenarios: {
    distributed_system_load: {
      executor: "constant-vus",
      vus: VUS,
      duration: DURATION,
      gracefulStop: "10s",
    },
  },
  thresholds: {
    http_req_failed: ["rate<0.05"],
    http_req_duration: ["p(95)<1000"],
    checks: ["rate>0.95"],
  },
};

const jsonHeaders = {
  headers: {
    "Content-Type": "application/json",
  },
};

function createResource(path, payload, name) {
  const response = http.post(`${BASE_URL}${path}`, JSON.stringify(payload), jsonHeaders);
  if (response.status < 200 || response.status >= 300) {
    fail(`Could not create ${name}. status=${response.status} body=${response.body}`);
  }
  return response.json();
}

export function setup() {
  const configuredUserId = Number(__ENV.CHECKOUT_USER_ID || 0);
  const configuredProductId = Number(__ENV.CHECKOUT_PRODUCT_ID || 0);

  if (configuredUserId > 0 && configuredProductId > 0) {
    return {
      userId: configuredUserId,
      productId: configuredProductId,
    };
  }

  const runId = Date.now();
  const user = createResource(
    "/users",
    {
      email: `k6-checkout-${runId}@example.com`,
      password: "password123",
    },
    "test user"
  );
  const product = createResource(
    "/products",
    {
      name: `K6 Checkout Product ${runId}`,
      description: "checkout load test product",
      price: "10.00",
      stock_quantity: CHECKOUT_STOCK,
    },
    "test product"
  );

  return {
    userId: user.id,
    productId: product.id,
  };
}

export default function (data) {
  const payload = {
    user_id: data.userId,
    items: [
      {
        product_id: data.productId,
        quantity: CHECKOUT_QUANTITY,
      },
    ],
  };
  const response = http.post(`${BASE_URL}/orders/checkout`, JSON.stringify(payload), {
    headers: {
      ...jsonHeaders.headers,
      "Idempotency-Key": `k6-${exec.vu.idInTest}-${exec.scenario.iterationInTest}`,
    },
    tags: {
      endpoint: "POST /orders/checkout",
    },
  });

  const instance = response.headers["X-Service-Instance"];
  if (instance === "finance-1") finance1Responses.add(1);
  if (instance === "finance-2") finance2Responses.add(1);

  check(response, {
    "checkout returned an expected status": (result) =>
      (result.status >= 200 && result.status < 300) ||
      result.status === 409 ||
      result.status === 429 ||
      result.status === 503,
    "checkout never returned 500 or 502": (result) =>
      result.status !== 500 && result.status !== 502,
    "finance instance header is present": (result) =>
      ["finance-1", "finance-2"].includes(result.headers["X-Service-Instance"]),
  });

  sleep(SLEEP_SECONDS);
}
