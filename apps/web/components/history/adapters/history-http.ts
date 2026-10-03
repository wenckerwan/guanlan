import type { HistoryRepository } from '../core/index';
import { validateDataset } from './validation';

/** 与上游 packages/adapters 相同的公开 URL 守卫：绝不向外暴露盘符/本地路径。 */
function isPublicUrl(value: string): boolean {
  if (!value || value.trim() !== value || /[\\]/.test(value) || value.startsWith('//')) return false;
  for (let i = 0; i < value.length; i++) { const code = value.charCodeAt(i); if (code >= 0 && code < 32) return false; }
  if (/^[a-z][a-z\d+.-]*:/i.test(value)) {
    try {
      const url = new URL(value);
      return (url.protocol === 'http:' || url.protocol === 'https:') && !url.username && !url.password;
    } catch { return false; }
  }
  return !value.split(/[?#]/)[0]!.split('/').includes('..');
}
const validPage = (page: number | null | undefined) => page == null || (Number.isSafeInteger(page) && page >= 1);

export interface GuanlanHistoryConfig {
  fetch: typeof fetch;
  contentEndpoint?: string;
  authHeaders?: () => HeadersInit | Promise<HeadersInit>;
  resolveSourceUrl: (documentId: string, page?: number | null) => string | null;
}

/** 观澜历史数据集仓库：GET {contentEndpoint}（默认 /api/v1/history/events）→ {data: HistoryDataset}。 */
export class GuanlanHistoryRepository implements HistoryRepository {
  private readonly endpoint: string;
  constructor(private readonly config: GuanlanHistoryConfig) {
    this.endpoint = config.contentEndpoint ?? '/api/v1/history/events';
    if (!isPublicUrl(this.endpoint)) throw new Error('观澜内容端点必须是公开 HTTP(S) 或相对 URL');
  }
  async loadDataset() {
    const headers = this.config.authHeaders ? await this.config.authHeaders() : undefined;
    const response = await this.config.fetch(this.endpoint, { headers });
    if (!response.ok) throw new Error(`观澜历史内容请求失败：HTTP ${response.status}`);
    const envelope: unknown = await response.json();
    if (typeof envelope !== 'object' || envelope === null || !('data' in envelope)) throw new Error('观澜内容响应缺少 data 包络');
    return validateDataset(envelope.data);
  }
  sourceUrl(sourceId: string, page?: number | null): string | null {
    if (!sourceId || !validPage(page)) return null;
    const url = this.config.resolveSourceUrl(sourceId, page);
    return url !== null && isPublicUrl(url) ? url : null;
  }
}
