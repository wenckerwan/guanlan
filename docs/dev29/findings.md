# findings

- 根目录规划是10月10日后台历史任务，已完成，保持不覆盖。
- 当前 VERSION 为 V0.1-dev.28。生产基线5ed992d。
- 史纲已有按账号IndexedDB分区、绑定凭证、dispose/abort及待同步机制，需要针对失败恢复验证。
- update.sh 仍在生产重建并以git reset/clean及重建旧源码回退，与低内存宿主和保留产物回滚目标冲突；需以运行时镜像切换替代。
- test_config.sh 目前将危险回退写法作为旧契约，需要改为可执行行为测试。
