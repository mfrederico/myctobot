## Future Scope - Job Queue Enhancements

- Priority-based queue scheduling for Jira tickets (use priority from analysis dataset)
- Daily sprint analysis to auto-prioritize tasks
- GitHub issues priority integration
- Parallel processing path (multiple workers per tenant)


## Vision OCR Enhancements

### Sliding Window OCR for Dense Pages
- Split pages into overlapping tiles for faster processing
- Challenge: Newspaper/multi-column layouts need layout-aware tiling
- Solution: Layout detection first (LayoutLM, docTR) then block-based OCR
- Preserves semantic relationships between content blocks

