import React from 'react'

function EmptyState({
  title,
  description,
  icon,
}: {
  title: string
  description: string
  icon?: React.ReactNode
}) {
  return (
    <div className="flex flex-col items-center justify-center space-y-4 m-12">
      {icon && <div className="text-foreground">{icon}</div>}
      <h2 className="text-zinc-500 text-lg font-semibold">{title}</h2>
      <p className="text-neutral-400 text-sm text-center">{description}</p>
    </div>
  )
}

export default EmptyState