import React from 'react'
import { Alert, AlertDescription, AlertTitle } from './alert'

function FormErrors({ errors }: { errors: Record<string, string> }) {
  if (Object.keys(errors).length === 0) {
    return null
  }

  return (
    <Alert>
      <AlertTitle>
        There were some problems with your input:
      </AlertTitle>

      <AlertDescription>
        <ul className="list-disc pl-5">
          {Object.entries(errors).map(([key, value]) => (
            <li key={key} className="text-red-500">
              {value}
            </li>
          ))}
        </ul>
      </AlertDescription>
    </Alert>
  )
}

export default FormErrors