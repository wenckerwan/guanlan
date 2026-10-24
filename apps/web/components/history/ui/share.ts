import type { HistoryDataset, HistoryEvent } from '../core/index';

export function historyCitation(event: HistoryEvent, dataset: HistoryDataset, url: string): string {
  const sources = (['textbook', 'original'] as const).flatMap(kind => {
    const date = event.dates[kind];
    if (!date) return [];
    const source = dataset.sources.find(item => item.id === date.sourceId);
    return [`${kind === 'textbook' ? '教材时间' : '原表时间'}：${date.label}；${source?.title || '来源未登记'}${date.page ? `，PDF 第 ${date.page} 页` : '，页码未定位'}`];
  });
  return [`${event.title}｜观澜史纲`, ...sources, `数据版本：${dataset.version}`, url].join('\n');
}

/** Failure leaves the exact text available for manual copying in the reader. */
export async function copyReaderText(text: string, clipboard?: Pick<Clipboard, 'writeText'>): Promise<boolean> {
  if (!clipboard) return false;
  try { await clipboard.writeText(text); return true; } catch { return false; }
}
