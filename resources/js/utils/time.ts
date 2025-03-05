export const timeAgo = (date: string) => {
    const now = new Date();
    const updated = new Date(date);
    const diffTime = Math.abs(now.getTime() - updated.getTime());
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

    if (diffDays === 1) return 'yesterday';
    if (diffDays < 30) return `${diffDays} days ago`;

    return updated.toLocaleDateString('en-US', {
        month: 'numeric',
        day: 'numeric',
        year: '2-digit',
    });
};
